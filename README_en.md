> 📖 [Versión en español](README.md)

# 📬 FOSSBilling Support Module (Enhanced)

Extended support module for FOSSBilling 8.2+. Adds complete management of open, replied, and reopened support tickets using email (POP3 + SMTP), spam filters via Sieve and SpamAssassin, staff-to-staff internal tickets, and advanced header tracking.

---

## ✨ Features


* **Three Ticket Types:** Built-in separation for regular **Clients**, the **General Public (guests)**, and **Staff Desk Users** (internal consultations sent from support agents to higher‑level administrators).
* **Per-Desk Email Accounts:** Configure dedicated email credentials for each support desk. Includes an integrated POP3/SMTP configuration form with connection testing tools.
* **Convert Guests to Clients:** One-click tool to add an external guest user into your client database directly from a public ticket, seamlessly migrating the entire message history to their new account.
* **Email Gateway:** Automated processing of incoming emails via POP3 using FOSSBilling's native Cron, parsing variables and assigning unique Ticket IDs.
* **Customized SMTP Relays:** Individual SMTP configuration for each department, ensuring replies originate from the specific helpdesk email address.
* **Advanced Spam Protection:** Full compatibility with **Sieve (ManageSieve)** and **SpamAssassin**.
* **Access Control Lists (ACL):** Global and per-desk Email Whitelists / Blacklists.
* **Keyword Filtering:** Advanced content filtering with support for email subjects, senders, body text, and custom headers.
* **Internal Staff Queries:** A dedicated "My Queries" menu allowing team members to monitor their internal escalations to upper management.
* **Email Thread Preservation:** Injects `Email-ID`, `In-Reply-To`, and `References` headers both in outgoing mails and the database. This ensures strict thread tracking and prevents emails from being flagged as spam by Gmail, Outlook, or Microsoft 365.
* **Smart Ticket Reopening:** Reopens closed tickets automatically upon receiving client replies, even if the user stripped the Ticket ID from the subject line.
* **Quote Sanitization:** Clean parsing of incoming mail replies, removing redundant email trails and signatures from Gmail, Outlook, iPhone, and Android mail clients.
* **Mailbox Auto-Discovery:** Automated home directory path mapping for major web panels (**DirectAdmin, cPanel, Plesk**) as well as custom paths.
* **Filter Control Dashboard:** Live management console for Sieve and SpamAssassin configurations with an instant rule-reset trigger.


---

## 📋 Requirements

* **FOSSBilling:** 0.8.2 or higher.
* **PHP:** 8.3 (as required by FOSSBilling 0.8.2).
* **IMAP/POP3 Server:** Dovecot with **ManageSieve enabled (Port 4190)** or SpamAssassin installed.
* **MTA:** Exim / Postfix with accessible POP3 mailboxes.

---

## ⚠️ Important Notice

> [!WARNING]
> This software is provided **"as is"** without warranty of any kind. The developers assume no liability for potential data loss, misconfigurations, or operational downtime. **ALWAYS BACK UP YOUR ENTIRE FOSSBILLING DIRECTORY AND DATABASE BEFORE PROCEEDING.**

This module replaces the core FOSSBilling `Support` module. It must be installed by overwriting the native folder on FOSSBilling v0.8.2+, as it depends on the database schema structures created by the original installation.

---

## 🚀 Installation

1. Download or clone this repository.
2. Back up your existing `/modules/Support` directory.
3. Copy the new `Support` folder into the `/modules/` directory of your FOSSBilling installation, fully replacing the native files.
4. The module will automatically check, update, and append the required custom tables to your database upon activation.

---

## ⚙️ Configuration

### 1. Create Support Desks (Helpdesks)
* Navigate to **System → Settings → Support → Support Departments**.
* Create your required departments (e.g., Support, Billing, Internal Staff, Public).
* Under 📬 **Email Gateway Configuration**, toggle the **Enable Ticket Reception by Email** switch.
* Fill out the form with your dedicated POP3 + SMTP details. **Important:** Always use matching inbound/outbound addresses per desk to prevent broken ticket threads.
* Define the **Access Level** (*Public, Clients, Staff, or Hybrid*).
* Assign your staff members under 👥 **Staff Assigned to This Desk**. (Staff members must be previously registered under *System → Settings → Staff → Personnel*).
* Verify successful database migrations at the bottom of the page under **⚠️ Installation of Support Tables** (Should display: `✅ All tables and columns are correctly installed.`).

### 2. Spam Filters & Access Lists
* The system automatically maps your environment for Sieve or SpamAssassin. If auto-detection fails, select your filter framework manually via the dropdown menu right above the Staff assignment block.
* Manage rules on demand under **Support → Sieve Configuration** or **Support → SpamAssassin**.
* Restrict or permit addresses via **Support → Email Whitelist** / **Email Blacklist**. *Note: Remember to click "Reset Filters" after updating your blacklists/whitelists to re-sync active rules.*

---

## 🛠️ Troubleshooting

### Inbound Mail Gateway Failure (Cron)

Ensure your system crontab triggers the FOSSBilling cron core using the **PHP 8.3** binary path:
```bash
/usr/local/php83/bin/php /path/to/FOSSBilling/cron.php
Verify that the department has enable_email = 1 set in the database and valid POP3 authentication credentials.
DirectAdmin Mail Delivery Blocked (Error 451)

If outbound logs (/var/log/exim/mainlog or data/log/php_error.log) reveal an Error 451 Temporary local problem pointing to auth_hit_limit_acl, your DirectAdmin environment contains an outdated custom Exim layout overriding current Perl script parameters (exim.pl).

Manual Workaround:

    Back up your current Exim setup: cp /etc/exim.conf /etc/exim.conf.backup

    Open your custom Exim override file: nano /usr/local/directadmin/custombuild/custom/exim/exim.conf

    Use Ctrl + W to locate auth_hit_limit_acl and comment out the block by appending # to match the example below:

text

# If you've hit the limit, you can't send anymore. Requires exim.pl 17+
#drop message = AUTH_TOO_MANY
#condition = \${perl{auth_hit_limit_acl}}
#authenticated = *

    Rebuild configuration parameters and restart Exim:

bash

cd /usr/local/directadmin/custombuild/
./build exim_conf
sudo systemctl restart exim

Permanent Fix (Recommended Automation Hook)

To prevent DirectAdmin updates from constantly overwriting this fix, deploy the provided custom hook script located in the tools/ directory.

Run the following commands as root:
bash

# Create the custom scripts directory if missing
mkdir -p /usr/local/directadmin/scripts/custom

# Create the post-build hook script
nano /usr/local/directadmin/scripts/custom/exim_post.sh

Paste the following script inside the file:
bash

#!/bin/bash
# Script to automatically comment out the auth_hit_limit_acl rule
# after DirectAdmin updates exim.conf

# Comment out the condition...${perl{auth_hit_limit_acl}} line
sed -i 's/^[[:space:]]*condition[[:space:]]*=[[:space:]]*\${perl{auth_hit_limit_acl}}/#&/' /etc/exim.conf

# Comment out the drop message = AUTH_TOO_MANY line
sed -i 's/^[[:space:]]*drop[[:space:]]*message[[:space:]]*=[[:space:]]*AUTH_TOO_MANY/#&/' /etc/exim.conf

# Comment out the authenticated = * line that follows the commented drop
sed -i '/^#[[:space:]]*drop[[:space:]]*message[[:space:]]*=[[:space:]]*AUTH_TOO_MANY/{ n; s/^[[:space:]]*authenticated[[:space:]]*=[[:space:]]*\*/#&/ }' /etc/exim.conf

# Restart Exim to apply changes
systemctl restart exim

Save and exit, then apply execution permissions to the script:
bash

chmod +x /usr/local/directadmin/scripts/custom/exim_post.sh

Test the Hook (Optional)

To verify the automation script executes correctly, run it manually:
bash

/usr/local/directadmin/scripts/custom/exim_post.sh

Then check your active configurations using grep:
bash

grep "auth_hit_limit_acl" /etc/exim.conf

You should see the target rule lines safely commented out with #.

With this hook in place, every time DirectAdmin regenerates the Exim configuration (either via an automated update or manual build commands), the script will trigger automatically and keep the problematic rule deactivated.
Emails from iPhone Arrive with Corrupted Characters

The module features a built-in mail sanitization engine that automatically decodes quoted-printable payloads and forces conversions to UTF-8. If encoding issues persist on specific tickets, verify whether the sender's client application is utilizing an uncommon or non-standard charset configuration.

🧑‍💻 Credits & Authors

    Lead Developer: Víctor Fornés for Inversiones Forma SPA (2026)

    Contributions: DeepSeek AI (2026)

☕ Support This Project

If you find this enhanced support module useful and want to back our development efforts, you can buy us a coffee via PayPal:

👉 **[Support via PayPal.Me](https://www.paypal.me/inversionesformaspa)**

Any support is highly appreciated! 🙏

🤝 Contributing

Contributions are always welcome. Feel free to open an issue or submit a pull request directly to this repository.
📄 License

This module is distributed under the GNU AGPL‑3.0 License, which is fully compatible with the core Apache 2.0 license utilized by FOSSBilling.
