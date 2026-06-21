# SUMO Reward Points — Purchase History Tier Multiplier Fix (WooCommerce HPOS)

**Not affiliated with the plugin author.** This is an independent bugfix for a
specific defect found in the "Purchase History based on Earning Level"
feature of the **SUMO Reward Points - WooCommerce Reward System** plugin
(CodeCanyon). It does not include or redistribute the plugin itself — only a
single corrected method you splice into your own already-licensed copy.

If you've purchased this plugin, enabled WooCommerce's High-Performance
Order Storage (HPOS), and configured multiple "Purchase History" tiers
(e.g. Silver / Gold / VIP based on lifetime spend), you may be affected by
this bug.

## Symptoms

- Every customer earns reward points at the same flat rate, regardless of
  how much they've actually spent historically.
- Customers who should clearly qualify for a higher tier (per your
  configured thresholds) only ever receive the base/lowest tier's
  percentage.
- This happens silently — no errors, no warnings, just incorrect point
  totals.

## How to confirm you're affected

1. Find a customer with enough lifetime spend to qualify for your highest
   configured tier (check **WooCommerce → Analytics → Customers** for their
   real lifetime total).
2. Have them place an order (or check a recent one).
3. Compare the points actually awarded against what your base
   rate × tier percentage should produce.

   Example: base rate is 2 points per $1, the customer's tier should be
   150%, and they spent $80 on the order. Expected points: 80 × 2 × 1.5 =
   240. If they instead received 160 (80 × 2 × 1.0 — the base/lowest tier
   rate), you're hitting this bug.

## Root cause

The bug lives in `purchase_history_percentage()`, a method on the plugin's
`RSMemberFunction` class. It has two independent defects that compound each
other:

**1. The lifetime-spend calculation isn't HPOS-aware.**
It calculates a customer's historical spend via a raw SQL query against
`wp_posts` / `wp_postmeta` looking for rows where `post_type = 'shop_order'`.
On a store using HPOS, real order data lives in the dedicated `wp_wc_orders`
table instead — orders are no longer stored as `wp_posts` rows of that post
type. The query silently returns nothing useful, so the customer's
calculated lifetime spend comes out as effectively $0, no matter how much
they've actually spent.

**2. The tier-matching loop stops at the first match instead of the best match.**
Even if the spend total were correct, the method loops through your
configured tiers and exits the loop (`break`) the moment *any* tier
matches — it doesn't continue checking for a better (higher) match. Since
your lowest tier typically has a $0 threshold, and the comparison is
"spend ≥ threshold," the lowest tier matches for literally every customer
and the loop exits before ever reaching your higher tiers. This means
higher tiers are unreachable by design, independent of bug #1.

Together, these guarantee every customer is always evaluated at your lowest
configured tier, regardless of real spending history.

## The fix

[`patch/purchase_history_percentage.fixed.php`](patch/purchase_history_percentage.fixed.php)
contains a corrected implementation of this one method. It:

- Calculates lifetime spend via a single aggregate SQL query against
  whichever order table is actually authoritative for your store (HPOS or
  legacy post-based storage) — no raw `wp_posts` query, no per-order object
  instantiation (so it stays fast even for customers with large order
  histories).
- Evaluates *all* configured tiers and picks the correct (highest
  qualifying, or tightest matching, depending on your range setting) tier
  instead of stopping at the first match.
- Avoids a secondary, more subtle bug in the original logic where matching
  tiers were tracked in an array keyed by the tier's threshold value — which
  silently collides if you ever configure an order-count-based rule and a
  spend-amount-based rule that happen to share the same numeric value (e.g.
  "50 orders" and "$50 spent" both writing to array key `50`).

### How to apply it

1. Open your own licensed copy of the plugin and locate the file defining
   `class RSMemberFunction` (typically under the plugin's `includes/`
   directory).
2. Find the existing `purchase_history_percentage()` method inside that
   class.
3. Replace the entire method body with the contents of
   [`patch/purchase_history_percentage.fixed.php`](patch/purchase_history_percentage.fixed.php)
   (it's written as a drop-in replacement — same method signature, same
   class context).
4. Save. No other part of the plugin needs to change.

**Heads up:** editing the plugin file directly means this patch will be
overwritten the next time you update the plugin. If you want a fix that
survives plugin updates, you'll need to prevent the patch from being
overwritten — for example by using PHP's `class_exists()` guard the plugin
itself already relies on (it skips defining `RSMemberFunction` if a class by
that name already exists) and defining your own complete version of the
class earlier in the load order, such as via a must-use plugin. That
approach isn't included in this repo since it requires copying the plugin's
other (unmodified) methods alongside the fix, and you should make sure
that's consistent with your license terms before doing so. The safest,
license-unambiguous option is always just reapplying this one-method patch
after each plugin update.

### Verifying the fix worked

After applying the patch, you can sanity-check it without placing a real
order. Via WP-CLI on your site:

```bash
wp eval 'echo RSMemberFunction::earn_points_percentage( <user_id>, 100 );'
```

Replace `<user_id>` with a customer ID known to qualify for a specific tier.
The output should reflect that tier's percentage of 100 (e.g. 150 for a
150% tier), not always the base tier's percentage.

## Should I report this to the plugin author instead?

Yes — please do, in addition to or instead of using this patch. This repo
exists because applying a fix yourself is faster than waiting on a vendor
release cycle, but the only way this gets fixed for *every* user of the
plugin (not just people who find this repo) is if the vendor ships it in an
official update. If you report it, feel free to link back here for the
technical detail.

## License

The contents of this repository (this README and the patch file) are
original work, provided under the MIT License — see [LICENSE](LICENSE).
This does not grant any rights to the SUMO Reward Points plugin itself,
which remains the property of its respective author and requires its own
valid license to use.
