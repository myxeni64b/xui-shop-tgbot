# Telegram Reseller Bot (JSON Edition) v1.1.4

A production-oriented Telegram reseller bot for selling pre-made accounts, subscription links, and similar digital items using **pure PHP + JSON storage**.

This edition is designed for environments where you do **not** want MySQL, SQLite, Composer packages, or any external library dependencies. It uses flat JSON files with file locking, atomic writes, runtime-editable settings, bilingual language files, and an admin panel operated from inside Telegram.

## Highlights

- Pure **PHP 5.6+** compatible codebase
- **No MySQL**, **no SQLite**, **no Composer**, **no external libraries**
- JSON-based storage with locking and atomic writes
- English and Persian language system from editable JSON files
- Full Telegram-based admin flow for daily operation
- Credit wallet + manual payment approval workflow
- Category-based stock selling with one-item assignment per successful purchase
- Sold-account history preserved even if a category is later removed
- Admin client management, announcements, credit tools, payment tools, and runtime settings
- Security hardening with request validation, limits, input normalization, and rate limiting

---

## Main Use Case

This bot is built for resellers who sell pre-made digital items such as:

- subscription links
- premium accounts
- login credentials
- invite links
- one-time-use account packages
- digital stock grouped by category

Each sellable item is stored as a separate stock entry. When a buyer purchases a category, one available item is assigned to that buyer and marked as sold.

---

## Feature Overview

### Buyer features

- Browse available categories that still have stock
- View category price and stock count
- Buy an item using wallet balance
- View purchased accounts in **My Accounts**
- View the actual bought link/account content after selecting a purchased item
- Add credit using one of the configured payment methods
- Submit payment proof as:
  - image receipt, or
  - transaction ID / payment link text
- Enter amount using numeric input only, with normalization for common formats
- Contact admin through the built-in support flow
- See support username in Contact section when configured
- Read Help & Tutorials plus configured help channel URL when set
- Change language between English and Persian
- Cancel active flows at any time with `/cancel` or the cancel keyboard

### Admin features

- Open admin panel directly inside Telegram with `/admin`
- Add, edit, restock, disable, or delete categories
- Soft-delete categories without removing already sold history
- Bulk-add stock items to a category
- View category stock statistics:
  - available qty
  - sold qty
  - archived qty
  - total items
- Receive automatic low-stock warning once when category stock drops below threshold
- Add, list, edit, enable/disable, and delete payment methods
- Review pending payments
- Approve payment
- Reject payment
- Edit amount and approve payment
- Search users by Telegram ID / username / name
- Browse user list with pagination
- View user profile details
- View a user’s credit and purchased accounts
- Add credit / bonus to a user
- Ban or unban a user with reason
- Send announcements to:
  - all users
  - users with balance
  - users who have purchased accounts
- Toggle sales globally on/off
- Toggle maintenance mode on/off
- Modify most runtime settings from inside the bot

---

## Technical Notes

### Storage engine

This project uses JSON files under `data/json/` as the main storage layer.

Core protections used by the storage layer:

- file-based locking for concurrent mutation
- atomic write strategy to reduce corruption risk
- simple repository layer for organized access
- no SQL requirement at all

### Language engine

Language strings are loaded from JSON files under `data/lang/`.

Included by default:

- `data/lang/en.json`
- `data/lang/fa.json`

You can modify these files or add additional languages later.

### Runtime settings

Operational texts and many non-sensitive settings are stored in `data/json/settings.json` and can be changed from the admin panel.

This includes items such as:

- banner text
- help text
- contact text
- currency
- support username
- help/tutorial channel URL
- payment min amount
- payment max amount
- low stock threshold
- sell enable/disable flag
- maintenance mode text and toggle

Sensitive bootstrap configuration still stays in `config.php`, such as:

- bot token
- admin Telegram IDs
- filesystem paths
- base URL
- webhook security secret

---

## Requirements

- PHP **5.6 or newer**
- HTTPS-enabled domain for Telegram webhooks
- Web server capable of running PHP scripts
- Write permissions for:
  - `data/json/`
  - `storage/logs/`
  - `storage/cache/`
  - `storage/locks/`

Recommended PHP extensions:

- `json`
- `mbstring` if available
- `curl` recommended but not strictly required in every flow

---

## Project Structure

```text
resellerbot-json-v1.1.4/
├── app/
│   ├── Core/
│   │   ├── Autoloader.php
│   │   ├── Logger.php
│   │   └── Utils.php
│   ├── Repositories/
│   │   ├── BaseRepository.php
│   │   ├── CategoryRepository.php
│   │   ├── ItemRepository.php
│   │   ├── OrderRepository.php
│   │   ├── PaymentMethodRepository.php
│   │   ├── PaymentRequestRepository.php
│   │   ├── SettingRepository.php
│   │   ├── TicketMessageRepository.php
│   │   ├── TicketRepository.php
│   │   └── UserRepository.php
│   ├── Security/
│   │   ├── LanguageManager.php
│   │   └── RateLimiter.php
│   ├── Services/
│   │   ├── BotService.php
│   │   └── TelegramApi.php
│   └── Storage/
│       ├── FileLock.php
│       └── JsonStore.php
├── config.php
├── config.example.php
├── data/
│   ├── json/
│   │   ├── categories.json
│   │   ├── items.json
│   │   ├── orders.json
│   │   ├── payment_methods.json
│   │   ├── payment_requests.json
│   │   ├── settings.json
│   │   ├── ticket_messages.json
│   │   ├── tickets.json
│   │   └── users.json
│   └── lang/
│       ├── en.json
│       └── fa.json
├── public/
│   └── bot.php
├── scripts/
│   ├── reset-json.php
│   └── set-webhook.php
└── storage/
    ├── cache/
    ├── locks/
    └── logs/
```

---

## Installation

### 1) Upload files

Upload the project to your server.

A common shared-hosting layout is:

```text
/home/USER/public_html/your-bot/
```

### 2) Configure the bot

Copy:

```text
config.example.php -> config.php
```

Then edit `config.php`.

At minimum, set:

- `bot_token`
- `bot_username`
- `admin_ids`
- `base_url`

Example:

```php
'bot_token' => '123456:ABC...'
'bot_username' => 'YourBotUsername'
'admin_ids' => array(123456789, 987654321)
'base_url' => 'https://example.com/your-bot/public'
```

### 3) Make directories writable

Ensure PHP can write to:

- `data/json/`
- `storage/logs/`
- `storage/cache/`
- `storage/locks/`

On Linux, a typical approach is:

```bash
chmod -R 0750 data storage
```

If needed, change ownership to the web server user.

### 4) Set the webhook

Run:

```bash
php scripts/set-webhook.php
```

This uses your configured `base_url` and sets:

```text
{base_url}/bot.php
```

If secret validation is enabled, the script also sends the secret token to Telegram.

### 5) Start using the bot

- Open your bot in Telegram
- Send `/start`
- From an admin account, send `/admin`

---

## Configuration Reference

### `config.php`

#### Root keys

- `bot_token` — Telegram bot token
- `bot_username` — bot username without `@` is typical, but either way is easy to manage
- `admin_ids` — array of Telegram user IDs allowed to use admin features
- `base_url` — full public URL to the `public` directory
- `timezone` — server-side timezone string

#### `security`

- `webhook_secret` — secret string for Telegram webhook validation
- `validate_webhook_secret` — turn on secret header validation
- `secret_header_name` — request header name Telegram uses for secret token
- `max_text_length` — max accepted text length for general text processing
- `max_caption_length` — max caption length for outbound media captions
- `request_size_limit` — max webhook payload size
- `strict_private_chat_only` — reject non-private chat usage
- `username_pattern` — regex for validating usernames when needed
- `payment_amount_min` — fallback minimum payment amount
- `payment_amount_max` — fallback maximum payment amount
- `state_ttl` — max age of temporary conversational state
- `callback_ttl` — callback lifetime guard
- `admin_reply_prefix` — admin ticket reply prefix, default `/reply_`
- `log_errors` — toggle internal error logging

#### `storage`

- `data_dir` — directory for JSON records
- `lang_dir` — language files directory
- `log_dir` — logs directory
- `cache_dir` — cache directory
- `lock_dir` — lock files directory
- `file_permissions` — file mode for created files
- `dir_permissions` — dir mode for created directories

#### `bot`

- `currency` — default currency code or label
- `default_language` — default new-user language
- `support_username` — shown in contact/help related sections when configured
- `help_channel_url` — tutorial/help channel URL shown in help section
- `maintenance_mode` — startup maintenance toggle
- `maintenance_text_en` / `maintenance_text_fa` — fallback maintenance text
- `require_join` — optional join enforcement scaffold
- `required_channels` — optional required channels list

#### `rate_limits`

- `global_user` — overall per-user webhook action limit
- `buy` — purchase-related action limit
- `contact` — support submission limit
- `payment_submit` — payment submission limit
- `admin_actions` — admin action rate limit

---

## JSON Data Files

The bot initializes and reads its data from these files:

- `users.json`
- `categories.json`
- `items.json`
- `orders.json`
- `payment_methods.json`
- `payment_requests.json`
- `tickets.json`
- `ticket_messages.json`
- `settings.json`

### Notes

- Sold orders are kept separately from category stock.
- Orders preserve snapshot fields to keep buyer history visible even if category metadata changes later.
- Category deletion is soft-delete oriented in this build.
- Unsold available stock can be archived without removing sold buyer records.

---

## Language System

### Included languages

- English: `data/lang/en.json`
- Persian: `data/lang/fa.json`

### To edit text

Open the language JSON file and change the values.

### To add a new language

1. Copy one of the existing files
2. Rename it to the new code, for example `ar.json`
3. Translate the values
4. Add language selection support if you want it exposed in the UI

### Runtime text vs language text

There are two levels of text configuration:

1. **Language files** for fixed interface strings
2. **Runtime settings** for editable business content such as banner/help/contact text

This gives you a good balance between structured translation and editable live content.

---

## Buyer Flow

### Start

Buyer sends `/start` and sees the main keyboard.

### Buy Account

1. Buyer opens **Buy Account**
2. Bot shows active categories with available stock only
3. Buyer selects category
4. Bot shows price, stock, and description
5. Buyer confirms purchase
6. Bot checks:
   - selling enabled
   - not in maintenance mode
   - enough balance
   - available item exists
7. One stock item is assigned and marked sold
8. Order record is created
9. Buyer can later open **My Accounts** and select the purchased item to see the actual account/link content

### Add Credit

1. Buyer opens **Add Credit**
2. Bot shows current credit, currency, min, and max payment limits
3. Buyer selects payment method
4. Bot shows payment instructions
5. Buyer sends receipt image or TXID/payment link
6. Buyer sends amount in numeric form
7. Amount is normalized and validated
8. Request is stored as pending
9. Admin reviews and approves/rejects/edits+approves

### Contact Us

1. Buyer opens **Contact Us**
2. Bot shows support guidance
3. If configured, support username is also displayed
4. Buyer sends message
5. Admin receives it and can reply using the configured reply prefix flow

### Help & Tutorials

This section shows:

- runtime help text
- help/tutorial channel URL if configured

---

## Admin Flow

### Enter admin panel

Use:

```text
/admin
```

### Main admin capabilities

#### Users

- view paginated client list
- search by Telegram ID, username, or name
- inspect user profile
- view user status and balance
- view purchased accounts for selected user
- add credit / bonus
- ban with reason
- unban

#### Categories

- add category
- edit category
- add stock in bulk
- delete category safely using soft-delete behavior
- inspect stock stats
- receive low stock notifications

#### Payment methods

- add method
- list methods
- inspect details
- edit method
- enable/disable method
- delete method

#### Payments

- inspect pending requests
- approve
- reject
- edit amount and approve

#### Announcements

Send messages to:

- all users
- users with balance
- users who bought at least one account

#### Settings

Modify most operational settings inside the bot, including:

- currency
- support username
- help channel URL
- payment min amount
- payment max amount
- low stock threshold
- bilingual banner text
- bilingual help text
- bilingual contact text
- bilingual sales-closed text
- bilingual maintenance text
- maintenance on/off
- selling on/off

---

## Commands

### Public / shared

- `/start`
- `/cancel`

### Admin

- `/admin`
- `/ban`
- `/unban`
- `/credit`
- `/user <id or search query>`

### Ticket reply

Default admin reply format:

```text
/reply_TICKETID your message here
```

This prefix is configurable in `config.php`.

---

## Payment Workflow Details

### Input behavior

Payment amount entry is designed to be forgiving and normalized.

Examples that can usually be normalized into a valid numeric amount:

- `25`
- `25.50`
- `25,50`
- `$25.50`
- `1,000`
- `1.000,50` depending on normalization logic and locale-like formatting

The bot validates the final numeric amount against configured min/max values.

### Approval options

Admin can:

- approve as submitted
- reject
- edit amount and approve

This is useful when a buyer types the amount slightly wrong or sends proof for a different amount than expected.

---

## Stock Preservation Behavior

This build specifically protects buyer history from disappearing when a category is deleted.

### Current behavior

When admin deletes a category:

- the category is soft-deleted
- unsold available items are archived
- sold items remain associated with existing orders
- buyer account history remains accessible
- snapshot values on orders help preserve display integrity

This was added intentionally so previous customers do not lose access to their purchased item information.

---

## Low Stock Alerts

Admins receive a one-time warning when a category falls below the configured threshold.

Default threshold behavior in this build:

- if available stock drops below `2`, admin is warned once
- if stock is replenished above threshold, notification state resets
- if it drops again later, admin can be warned again

Threshold can be adjusted from runtime settings.

---

## Security Model

This project is flat-file based, so security and data integrity matter even more.

Included protections:

- optional Telegram webhook secret validation
- request size limit
- private-chat-only mode
- state TTL expiration
- callback lifetime checks
- rate limits by action type
- input trimming and sanitization
- text length limits
- numeric normalization for payment amounts
- file locking for critical mutations
- atomic JSON writes
- reduced race condition risk during purchase and payment approval flows
- soft-delete patterns for safer history preservation

### Important security notes

- Keep `config.php` outside public exposure when possible.
- The `public/` directory should be the web entry point.
- Do not expose raw storage directories for public listing.
- Use HTTPS only.
- Set a strong webhook secret if validation is enabled.
- Back up `data/json/` regularly.

---

## Backup Strategy

At minimum, back up:

- `data/json/`
- `data/lang/`
- `config.php`

Recommended frequency:

- before any major admin edit session
- daily if you have active sales
- before deploying a new version

Because this is JSON storage, backups are especially important.

---

## Updating the Bot

### Safe update process

1. Back up current project
2. Back up `data/json/`
3. Back up `data/lang/`
4. Replace application files
5. Preserve your existing `config.php`
6. Compare any new default config keys from `config.example.php`
7. Test `/start`, `/admin`, payment flow, and one purchase flow

### Version note

This README targets:

```text
v1.1.4
```

---

## Troubleshooting

### Bot does not respond

Check:

- webhook is set correctly
- `base_url` points to `/public`
- HTTPS certificate is valid
- `public/bot.php` is reachable
- PHP error logs

### Webhook appears set but still no updates

Check:

- secret validation mismatch
- request blocked by firewall or WAF
- wrong bot token
- wrong base URL
- server returning fatal error

### JSON not updating

Check:

- file ownership
- write permissions
- lock directory permissions
- disk space

### Buyer cannot purchase despite stock existing

Check:

- sales are enabled
- maintenance mode is disabled
- category is active
- user has enough credit
- item status is still `available`

### Help channel or support username not shown

Check runtime settings and config fallback values:

- `support_username`
- `help_channel_url`

If you migrated from an older version, ensure settings were seeded or re-saved correctly.

---

## Honest Limitations

This project is strong for a flat-file bot, but there are natural limitations to JSON storage:

- it is weaker than a database under high concurrency
- extremely heavy traffic can still stress flat-file locking
- large datasets will eventually be slower to scan than indexed database storage

For small to medium reseller bots, this approach is usually acceptable.

For larger scale, **SQLite** would be the best next upgrade while still avoiding MySQL.

---

## Changelog Summary for v1.1.4

- preserved sold buyer history when categories are deleted
- changed category deletion behavior to soft-delete / archive unsold stock
- added order snapshot support for safer future display
- added admin payment method list/manage/edit/enable-disable/delete flow
- added category quantity and sold counters in admin UI
- added category stats screen with available/sold/archived/total
- added automatic one-time low stock warning under threshold
- fixed help/tutorial section to correctly show configured help channel URL
- improved fallback handling for runtime contact/help settings

---

## Recommended GitHub Description

**Production-oriented Telegram reseller bot in pure PHP with JSON storage, bilingual EN/FA interface, admin panel, wallet, manual payment approval, stock management, client tools, and security hardening — no MySQL and no external libraries.**

---

## Final Notes

This project aims to provide a serious, self-contained reseller bot foundation without database or external package dependencies.

It is suitable for:

- shared hosting
- lightweight VPS deployments
- operators who want Telegram-first management
- setups where Composer or MySQL are undesirable

If you continue extending it, the best next structural upgrades would be:

- optional SQLite backend
- admin action log viewer
- export/import tools
- stronger queueing for large broadcasts
- optional media/tutorial gallery support
- coupon/referral system

