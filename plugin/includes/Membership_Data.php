<?php
/**
 * WooCommerce Memberships reader for the Cognito sync payload
 *
 * @package WP_Cognito_Auth
 */

namespace WP_Cognito_Auth;

/**
 * Class Membership_Data
 *
 * Builds the `membership` block carried at the TOP LEVEL of the /sync payload.
 *
 * This is the first WooCommerce-aware behaviour in the plugin. Everything else
 * here is deliberately WooCommerce-blind: the plugin is a dumb pipe and the
 * Lambda owns all mapping. This block is the one exception, because MyBosun
 * needs the club's paid-through date to migrate membership ownership away from
 * WooCommerce, and that date exists nowhere else.
 *
 * ---------------------------------------------------------------------------
 * THE AUTHORITATIVE PAID-THROUGH DATE IS THE SUBSCRIPTION'S NEXT PAYMENT DATE
 * ---------------------------------------------------------------------------
 * Verified against the live database (2026-09-20):
 *
 *   wc_user_membership._end_date        set on   1 of 717 active memberships
 *   shop_subscription._schedule_next_payment  set on 922 of 924 subscriptions
 *
 * These are subscription-tied memberships: WooCommerce leaves the membership
 * end date blank and lets the membership live as long as its subscription.
 * Reading `_end_date` would export almost every member with no renewal date
 * and lapse them the moment MyBosun took ownership. `endDate` is still carried
 * for completeness, but `nextPaymentDate` is the field the import trusts.
 *
 * ---------------------------------------------------------------------------
 * WHY THE BLOCK IS TOP-LEVEL AND CARRIES RANK/CATEGORY ITSELF
 * ---------------------------------------------------------------------------
 * `User_Data` nests `wp_memberrank` / `wp_membercategory` under `extra`, but
 * the Lambda has always read them from the top level — which is why the
 * `custom:wp_memberrank` / `custom:wp_membercategory` Cognito attributes are
 * unset on every user in the live pool. This block sits beside `extra`, not
 * inside it, and carries the two fields itself so the Lambda finds them.
 *
 * ---------------------------------------------------------------------------
 * BLOCK SHAPE
 * ---------------------------------------------------------------------------
 *   {
 *     "plan":               "members",            // WC plan SLUG
 *     "planName":           "Members",
 *     "status":             "active",             // WC membership status, no wcm- prefix
 *     "startDate":          "2019-04-01T00:00:00+00:00",
 *     "endDate":            null,                 // almost always null — see above
 *     "nextPaymentDate":    "2027-04-01T00:00:00+00:00",  // THE paid-through date
 *     "autoRenew":          true,
 *     "wcMembershipId":     123,
 *     "wcSubscriptionId":   456,                  // 0 when none (honorary/dependent)
 *     "subscriptionStatus": "active",             // WC subscription status, no wc- prefix
 *     "amount":             1500,                 // MINOR units (pence), from the subscription
 *     "currency":           "GBP",
 *     "billingInterval":    1,
 *     "billingPeriod":      "year",
 *     "membershipCount":    1,                    // >1 drives the multi-membership exception
 *     "otherMemberships":   [ { plan, status, wcMembershipId, wcSubscriptionId, nextPaymentDate } ],
 *     "memberRank":         "...",
 *     "memberCategory":     "...",
 *     "capturedAt":         "2026-09-20T09:00:00+00:00"
 *   }
 *
 * Every value is read defensively: a WooCommerce upgrade that renames a method,
 * or a site with Memberships deactivated, must degrade to "no membership block"
 * and never break identity sync.
 */
class Membership_Data {

	/**
	 * WC membership statuses that represent a live, entitled membership.
	 *
	 * Deliberately NOT a filter for what gets sent — every status is sent, and
	 * MyBosun decides what to import (paused and on-hold are not migrated).
	 * This list only picks which membership is the PRIMARY one when a member
	 * holds several.
	 *
	 * @var string[]
	 */
	const LIVE_STATUSES = array( 'active', 'complimentary', 'free_trial', 'pending' );

	/**
	 * Is WooCommerce Memberships available on this install?
	 *
	 * @return bool
	 */
	public static function is_available() {
		return function_exists( 'wc_memberships_get_user_memberships' );
	}

	/**
	 * Build the membership block for a user, or null when there is nothing to say.
	 *
	 * @param int      $user_id WordPress user ID.
	 * @param \WP_User $user    WordPress user object (unused today, passed to the filter).
	 * @return array|null Membership block, or null.
	 */
	public static function prepare( $user_id, $user = null ) {
		$block = null;

		if ( self::is_available() ) {
			try {
				$block = self::build( $user_id );
			} catch ( \Exception $e ) {
				// A membership read must never break identity sync. Log and drop.
				self::log( "Membership block build failed for user {$user_id}: " . $e->getMessage() );
				$block = null;
			} catch ( \Error $e ) {
				self::log( "Membership block build errored for user {$user_id}: " . $e->getMessage() );
				$block = null;
			}
		}

		/**
		 * Filter the membership block sent to the MyBosun sync Lambda.
		 *
		 * Lets a tenant with a different membership plugin supply the same
		 * shape, or suppress the block entirely by returning null.
		 *
		 * @param array|null $block   The membership block (null when none).
		 * @param int        $user_id WordPress user ID.
		 * @param \WP_User   $user    WordPress user object.
		 */
		return apply_filters( 'wp_cognito_user_membership_block', $block, $user_id, $user );
	}

	/**
	 * Read WooCommerce Memberships and assemble the block.
	 *
	 * @param int $user_id WordPress user ID.
	 * @return array|null
	 */
	private static function build( $user_id ) {
		$memberships = wc_memberships_get_user_memberships( $user_id, array( 'status' => 'any' ) );

		if ( empty( $memberships ) || ! is_array( $memberships ) ) {
			return null;
		}

		$summaries = array();
		foreach ( $memberships as $membership ) {
			$summary = self::summarise( $membership );
			if ( null !== $summary ) {
				$summaries[] = $summary;
			}
		}

		if ( empty( $summaries ) ) {
			return null;
		}

		usort( $summaries, array( __CLASS__, 'compare_primacy' ) );
		$primary = array_shift( $summaries );

		$block = array(
			'plan'               => $primary['plan'],
			'planName'           => $primary['planName'],
			'status'             => $primary['status'],
			'startDate'          => $primary['startDate'],
			'endDate'            => $primary['endDate'],
			'nextPaymentDate'    => $primary['nextPaymentDate'],
			'autoRenew'          => $primary['autoRenew'],
			'wcMembershipId'     => $primary['wcMembershipId'],
			'wcSubscriptionId'   => $primary['wcSubscriptionId'],
			'subscriptionStatus' => $primary['subscriptionStatus'],
			'amount'             => $primary['amount'],
			'currency'           => $primary['currency'],
			'billingInterval'    => $primary['billingInterval'],
			'billingPeriod'      => $primary['billingPeriod'],
			// A member with more than one live WC membership (family +
			// individual) is reported for a human to confirm, never resolved
			// automatically. The count and the tail are what makes that
			// classification possible on the MyBosun side.
			'membershipCount'    => count( $summaries ) + 1,
			'otherMemberships'   => self::tail_summaries( $summaries ),
			'memberRank'         => self::meta_string( $user_id, 'wpuef_cid_c6' ),
			'memberCategory'     => self::meta_string( $user_id, 'wpuef_cid_c10' ),
			'capturedAt'         => gmdate( 'c' ),
		);

		return $block;
	}

	/**
	 * Reduce one WC_Memberships_User_Membership to a plain array.
	 *
	 * @param object $membership WC user membership object.
	 * @return array|null
	 */
	private static function summarise( $membership ) {
		if ( ! is_object( $membership ) || ! method_exists( $membership, 'get_id' ) ) {
			return null;
		}

		$plan_slug = '';
		$plan_name = '';
		if ( method_exists( $membership, 'get_plan' ) ) {
			$plan = $membership->get_plan();
			if ( is_object( $plan ) ) {
				if ( method_exists( $plan, 'get_slug' ) ) {
					$plan_slug = (string) $plan->get_slug();
				}
				if ( method_exists( $plan, 'get_name' ) ) {
					$plan_name = (string) $plan->get_name();
				}
			}
		}

		$subscription_id = self::resolve_subscription_id( $membership );
		$subscription    = self::resolve_subscription( $subscription_id );

		$next_payment = null;
		$sub_status   = null;
		$amount       = null;
		$currency     = null;
		$interval     = null;
		$period       = null;

		if ( $subscription ) {
			// THE authoritative paid-through date. `get_date` returns UTC
			// 'Y-m-d H:i:s' (or '' when the schedule is empty).
			if ( method_exists( $subscription, 'get_date' ) ) {
				$next_payment = self::to_iso8601( $subscription->get_date( 'next_payment' ) );
			}
			if ( null === $next_payment ) {
				// Direct meta fallback — the field verified against the live DB.
				$next_payment = self::to_iso8601( get_post_meta( $subscription_id, '_schedule_next_payment', true ) );
			}
			if ( method_exists( $subscription, 'get_status' ) ) {
				$sub_status = (string) $subscription->get_status();
			}
			if ( method_exists( $subscription, 'get_total' ) ) {
				// MINOR units, to match the MyBosun money convention. The live
				// data has £15 (the standard plan) plus a handful of £30/£45/£0
				// outliers, so the per-member amount travels rather than being
				// assumed from the plan.
				$amount = (int) round( (float) $subscription->get_total() * 100 );
			}
			if ( method_exists( $subscription, 'get_currency' ) ) {
				$currency = (string) $subscription->get_currency();
			}
			if ( method_exists( $subscription, 'get_billing_interval' ) ) {
				$interval = (int) $subscription->get_billing_interval();
			}
			if ( method_exists( $subscription, 'get_billing_period' ) ) {
				$period = (string) $subscription->get_billing_period();
			}
		}

		$status = method_exists( $membership, 'get_status' ) ? (string) $membership->get_status() : '';

		return array(
			'plan'               => $plan_slug,
			'planName'           => $plan_name,
			'status'             => $status,
			'startDate'          => self::membership_date( $membership, 'start' ),
			'endDate'            => self::membership_date( $membership, 'end' ),
			'nextPaymentDate'    => $next_payment,
			// Auto-renew means "WooCommerce will collect again on its own".
			// A cancelled or pending-cancel subscription will not.
			'autoRenew'          => ( 'active' === $sub_status ),
			'wcMembershipId'     => (int) $membership->get_id(),
			'wcSubscriptionId'   => $subscription_id,
			'subscriptionStatus' => $sub_status,
			'amount'             => $amount,
			'currency'           => $currency,
			'billingInterval'    => $interval,
			'billingPeriod'      => $period,
		);
	}

	/**
	 * The slim shape carried for a member's non-primary memberships.
	 *
	 * @param array $summaries Remaining summaries.
	 * @return array
	 */
	private static function tail_summaries( array $summaries ) {
		$out = array();
		foreach ( $summaries as $s ) {
			$out[] = array(
				'plan'             => $s['plan'],
				'status'           => $s['status'],
				'wcMembershipId'   => $s['wcMembershipId'],
				'wcSubscriptionId' => $s['wcSubscriptionId'],
				'nextPaymentDate'  => $s['nextPaymentDate'],
			);
		}
		return $out;
	}

	/**
	 * Sort comparator picking the primary membership.
	 *
	 * Live statuses first, then subscription-backed, then the earliest next
	 * payment date, then the lowest membership id so the choice is stable
	 * across runs (an unstable primary would show up as spurious divergence on
	 * every reconciliation).
	 *
	 * @param array $a Summary.
	 * @param array $b Summary.
	 * @return int
	 */
	private static function compare_primacy( $a, $b ) {
		$a_live = in_array( $a['status'], self::LIVE_STATUSES, true ) ? 0 : 1;
		$b_live = in_array( $b['status'], self::LIVE_STATUSES, true ) ? 0 : 1;
		if ( $a_live !== $b_live ) {
			return $a_live - $b_live;
		}

		$a_dated = empty( $a['nextPaymentDate'] ) ? 1 : 0;
		$b_dated = empty( $b['nextPaymentDate'] ) ? 1 : 0;
		if ( $a_dated !== $b_dated ) {
			return $a_dated - $b_dated;
		}

		if ( 0 === $a_dated && $a['nextPaymentDate'] !== $b['nextPaymentDate'] ) {
			return strcmp( $a['nextPaymentDate'], $b['nextPaymentDate'] );
		}

		return $a['wcMembershipId'] - $b['wcMembershipId'];
	}

	/**
	 * Read a membership start/end date as ISO-8601, or null.
	 *
	 * @param object $membership WC user membership.
	 * @param string $which      'start' or 'end'.
	 * @return string|null
	 */
	private static function membership_date( $membership, $which ) {
		$method = 'start' === $which ? 'get_start_date' : 'get_end_date';
		if ( ! method_exists( $membership, $method ) ) {
			return null;
		}
		$value = $membership->$method( 'mysql' );
		return self::to_iso8601( $value );
	}

	/**
	 * Resolve the linked WC Subscriptions id for a membership.
	 *
	 * @param object $membership WC user membership.
	 * @return int Subscription post id, or 0.
	 */
	private static function resolve_subscription_id( $membership ) {
		if ( method_exists( $membership, 'get_subscription_id' ) ) {
			$id = (int) $membership->get_subscription_id();
			if ( $id > 0 ) {
				return $id;
			}
		}
		if ( method_exists( $membership, 'get_id' ) ) {
			$meta = get_post_meta( $membership->get_id(), '_subscription_id', true );
			if ( ! empty( $meta ) ) {
				return (int) $meta;
			}
		}
		return 0;
	}

	/**
	 * Load a WC_Subscription, or false.
	 *
	 * @param int $subscription_id Subscription post id.
	 * @return object|false
	 */
	private static function resolve_subscription( $subscription_id ) {
		if ( $subscription_id <= 0 || ! function_exists( 'wcs_get_subscription' ) ) {
			return false;
		}
		$subscription = wcs_get_subscription( $subscription_id );
		return is_object( $subscription ) ? $subscription : false;
	}

	/**
	 * Convert a WooCommerce UTC 'Y-m-d H:i:s' timestamp to ISO-8601, or null.
	 *
	 * @param mixed $value MySQL-format UTC datetime.
	 * @return string|null
	 */
	private static function to_iso8601( $value ) {
		if ( empty( $value ) || ! is_string( $value ) ) {
			return null;
		}
		// WooCommerce returns '0000-00-00 00:00:00' for an unset schedule.
		if ( 0 === strpos( $value, '0000-00-00' ) ) {
			return null;
		}
		$timestamp = strtotime( $value . ' UTC' );
		if ( false === $timestamp || $timestamp <= 0 ) {
			return null;
		}
		return gmdate( 'c', $timestamp );
	}

	/**
	 * Read a user meta value as a trimmed string, or null when empty.
	 *
	 * @param int    $user_id WordPress user ID.
	 * @param string $key     Meta key.
	 * @return string|null
	 */
	private static function meta_string( $user_id, $key ) {
		$value = get_user_meta( $user_id, $key, true );
		if ( ! is_scalar( $value ) ) {
			return null;
		}
		$value = trim( (string) $value );
		return '' === $value ? null : $value;
	}

	/**
	 * Log without depending on the API component.
	 *
	 * @param string $message Message.
	 * @return void
	 */
	private static function log( $message ) {
		if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
			error_log( '[wp-cognito-auth] ' . $message ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
		}
	}
}
