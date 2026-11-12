# YSRTech_GuestToCustomer

An OpenMage / Magento 1.x module that automatically links **guest orders** to an
existing **registered customer account** whenever the order's email matches a
registered customer on the **same website**.

It can link orders at three points:

1. **On order placement** (`sales_order_place_after`) — a guest checkout whose
   email already belongs to a registered customer is linked immediately.
2. **On customer registration** (`customer_register_success`) — when someone
   registers, any pre-existing guest orders matching their email on their
   website are linked.
3. **Retroactively** via a shell script, for orders placed before the module
   was installed.

## Accounts for guests (optional, off by default)

Linking only helps when the guest already has an account. Switch on
**Accounts For Guests** and a guest who has none is given one, so that everybody
who has ever ordered is a customer with their orders, addresses and history in
one place.

The account is made quietly:

- **No email is sent** - no welcome, no confirmation, no newsletter notice.
- **The password is random** and known to nobody. The person signs in by
  resetting it (or through whatever passwordless login the store has).
- **Nobody is subscribed to the newsletter.** A newsletter row that already
  exists for the address is attached to the new account with its status
  exactly as it was.
- The account belongs to the order's website and the store's default customer
  group; the name comes from the order (or its billing address).
- The order's billing and shipping addresses become the account's default
  addresses (can be switched off).
- An order whose email is not a valid address is left alone.

| Setting | Config path | Default |
| --- | --- | --- |
| Create Accounts For Guests | `ysrtech_guesttocustomer/create/enabled` | No |
| Create As Each Guest Order Is Placed | `ysrtech_guesttocustomer/create/on_order_place` | Yes |
| Copy The Order Addresses | `ysrtech_guesttocustomer/create/copy_addresses` | Yes |

For the guests who ordered before it was switched on:

```bash
php shell/guesttocustomer.php --dry-run --create   # what it would do
php shell/guesttocustomer.php --create --limit 20  # try it on a few
php shell/guesttocustomer.php --create --quiet     # everything, totals only
```

The script works newest order first, so an account takes its name and
addresses from the person's latest order. `--create` works whether or not the
setting above is on. Whether your store may create accounts for people who
chose to check out as guests is a question for your privacy policy.

## Important: per-website email uniqueness

Magento enforces customer email uniqueness **per website** by default (account
sharing scope = *Website*). The same email may belong to different accounts on
different websites. Every lookup in this module is therefore **scoped to the
order's website** using
`Mage::getModel('customer/customer')->setWebsiteId($websiteId)->loadByEmail($email)`,
so an order is never linked to a customer that belongs to another website.

## What it changes (and what it does not)

Only the customer-association fields on the order are modified:

- `customer_id`
- `customer_is_guest` → `0`
- `customer_group_id` (taken from the matched customer)

It does **not** alter the order email, invoices, shipments, credit memos,
totals, or any other historical/financial records. The operation is
**idempotent** — already-linked orders are skipped.


## Configuration

`System > Configuration > YSRTech > Guest To Customer`
(configurable at Default and Website scope):

| Setting | Config path | Default |
| --- | --- | --- |
| Enabled (master switch) | `ysrtech_guesttocustomer/general/enabled` | Yes |
| Link On Order Placement | `ysrtech_guesttocustomer/triggers/on_order_place` | Yes |
| Link On Customer Registration | `ysrtech_guesttocustomer/triggers/on_customer_register` | Yes |

When **Enabled** is set to *No*, no trigger links any order.

## Retroactive sync (shell script)

Run from the Magento root:

```bash
# Link all matching existing guest orders
php shell/guesttocustomer.php

# Preview only — logs/prints what WOULD change, saves nothing
php shell/guesttocustomer.php --dry-run

# Tune chunk size for very large order tables (default 500)
php shell/guesttocustomer.php --batch-size 1000

# Run even if the module is disabled in config
php shell/guesttocustomer.php --force

# Help
php shell/guesttocustomer.php help
```

The script loads guest orders (`customer_is_guest = 1`) with a non-empty email
in chunks, links each via the shared helper, and prints a summary count at the
end.

## Reusable helper

All linking logic lives in one place so it can be called from observers, the
shell, or admin/custom code:

```php
/** @var YSRTech_GuestToCustomer_Helper_Data $helper */
$helper = Mage::helper('ysrtech_guesttocustomer');

// Link a single order (returns bool). Pass true for dry-run.
$helper->linkOrderToCustomer($order);

// Link all guest orders for a customer on their website (returns count).
$helper->linkGuestOrdersForCustomer($customer);
```

## Logging

All actions and errors are written to a dedicated log file:

```
var/log/ysrtech_guesttocustomer.log
```

Observer failures are caught and logged so they can never block checkout or
registration.
