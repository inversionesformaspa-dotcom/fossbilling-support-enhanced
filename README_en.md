# 📬 FOSSBilling Support Module (Enhanced)  

> 📖 [Versión en español](README.md)

Extended support module for FOSSBilling 8.2+. Adds complete management of open, replied, and reopened support tickets using email (POP3 + SMTP), spam filters with Sieve and SpamAssassin, staff tickets, and much more.

## ✨ Features

- ✅ **Three ticket types**: For Clients, for the General Public (guests), and for Staff Desk Users (internal consultations from support staff to higher‑level administrators).
- ✅ **Allows configuration of an email account for each support desk** This enables custom filters and includes a POP3 and SMTP configuration form and configuration testing.
- ✅ **Add a user from a public ticket to your client list** This also allows you to move the support thread to the selected support desk, so the newly created client can view it from their client account.
- ✅ **Email Gateway**: Automatic processing of incoming emails via POP3 using FOSSBilling's native Cron and ticket ID assignment.
- ✅ **Customized response delivery** for each support desk (SMTP)
- ✅ **Support for Sieve** (ManageSieve) and **SpamAssassin**
- ✅ **Whitelist / Blacklist** of emails per desk
- ✅ **Anti‑spam keyword management** with support for subject, sender, body, and headers
- ✅ **"My Queries" menu** so each staff member can view their own open tickets with the higher‑level administrator.
- ✅ **Email‑ID, in‑reply‑to, and reference headers**: Added to email headers and the database, allowing for better control of support thread assignment and preventing emails from being marked as spam by Gmail, Outlook, etc.
- ✅ **Reopen tickets** when replying to closed emails, even if the subject ID is deleted
- ✅ **Cleaning and sanitization of quotes** in replies (Gmail, Outlook, iPhone)
- ✅ **Auto‑detection of mailbox paths** (DirectAdmin, cPanel, Plesk, custom path)
- ✅ **Filter control panel** (Sieve / SpamAssassin) with a filter reset button

## 📋 Requirements

- **FOSSBilling 8.2** or higher
- **PHP 8.3** (required by FOSSBilling 8.2)
- **Dovecot** with ManageSieve enabled (port 4190) **or** SpamAssassin installed
- **Exim / Postfix** with POP3 mailboxes accessible

⚠️ Important notice for users

This module replaces the original Support module of FOSSBilling and must be installed by replacing the native Support module of FOSSBilling 0.8.2 and/or after having FOSSBilling updated to version 0.8.2, as it requires the database tables created by the original version.

## 🚀 Installation

This software is provided as is. We are not responsible for any failure or resulting damage, whether from the software itself, incorrect use, or failure to follow recommendations. We also do not provide normal, priority, or any other kind of support.
**MAKE A BACKUP OF YOUR ENTIRE FOSSBilling INSTALLATION AND DATABASE BEFORE INSTALLING THIS MODULE.**
1. Download or clone this repository.
2. Copy the `Support` folder into `/modules/` of your FOSSBilling installation, replacing the existing Support folder (make a copy of the Support folder before overwriting it).
3. The module will automatically create the necessary tables in the database.

## ⚙️ Configuration

### 1. Create support desks (Helpdesks)
- Go to **System → Settings → Support → Support Departments**.
- Create a desk for each area (Support, Sales, Staff, Public, etc.).
- In 📬 Email Gateway Configuration, click the **Enable Ticket Reception by Email** selector.
- In the form that appears below the selector, configure the **Email Gateway** (POP3 + SMTP) to start receiving tickets by email. Use the same email account; otherwise, support tickets will not be opened or support threads will be created/broken.
- Select the **Access Level** to define who can open tickets by writing to the support desk email (Public, Clients, Staff, Hybrid).
- In 👥 Staff Assigned to This Desk – Support Users Who Handle This Desk
- Select the **Staff** who will manage the support desk. To appear in the list, you must first create the staff in **System → Settings → Staff → Personnel**.
- At the bottom of the support desk creation page, you will see the table creation confirmation in the block  
⚠️ Installation of Support Tables.  
✅ All tables and columns are correctly installed.

### 2. Configure spam filters
- The module automatically detects whether you use **Sieve** or **SpamAssassin**. If it cannot detect them, when creating the support desk, just above the 👥 Staff Assigned to This Desk block there is a dropdown to select the type of filter the module will use. If you do not know which type of email filter (Sieve or SpamAssassin) to use, use the default one.
- In **Support → Sieve Configuration** or **Support → SpamAssassin** you can view the status and reset filters.

### 3. Configure blacklists or whitelists
- In **Support → Email Whitelist** or **Email → Blacklist** you can allow or block emails per support desk.
- Once you have saved your changes to the whitelists or blacklists, go to **Support → Sieve Configuration** or **Support → SpamAssassin** and reset the filters.

### 4. Assign staff to desks
- When editing a desk, select the staff members who will be able to view and respond to its tickets.

### 5. Mailbox path (SpamAssassin only)
- If you use SpamAssassin and the system cannot find the path, go to **System → Settings → Support** and select your control panel (DirectAdmin, cPanel, Plesk) or enter a custom path.

## 🛠️ Troubleshooting

### The cron does not process incoming emails
- Make sure the FOSSBilling cron runs with **PHP 8.3**:
  ```bash
  /usr/local/php83/bin/php /path/to/FOSSBilling/cron.php

    Verify that helpdesks have enable_email = 1 and correct POP3 credentials.

ON DIRECTADMIN

Emails are not received or sent, and nothing appears in the pending emails tab.
Check tail -20 /var/log/exim/mainlog | grep "soporte@domain.tld" or the email account of the affected support desk.
You may also find results in the root directory of your FOSSBilling installation: tail -30 data/log/php_error.log (If you don't see results, you may need to enable debugging in your config.php file).
If it shows something like
451 Temporary local problem in the logs, or Error 451 when sending replies, or any reference to auth_hit_limit_acl
It is an Exim problem:
https://forum.directadmin.com/threads/please-help-exim-rejecting-all-incoming-emails-since-da-1-702-update-solved.82291/

In DirectAdmin, sometimes a residual version of a custom Exim installation remains, and the modules, in this case Perl, are not updated or the installation was not compiled correctly.
FIRST, MAKE A COPY OF YOUR EXIM CONFIGURATION FILE:
GENERALLY
cp /etc/exim.conf /etc/exim.conf.backup
To verify, use the command
ls /etc
and look for the exim.conf.backup file.

Check ls /usr/local/directadmin/custombuild/custom/exim
If you see the exim.conf file, save a copy of this file outside the custombuild directory and delete it from custombuild, OR edit it and search for the following block and comment it out.

nano /usr/local/directadmin/custombuild/custom/exim/exim.conf
Keys Ctrl + w and enter the term auth_hit_limit_acl
The block should look something like the following:
text

  # If you've hit the limit, you can't send anymore. Requires exim.pl 17+
  #drop  message = AUTH_TOO_MANY
  #      condition = ${perl{auth_hit_limit_acl}}
  #      authenticated = *

When it is commented out, use keys Ctrl + s and Ctrl + x
cd /usr/local/directadmin/custombuild/ and ./build exim_conf or cd /usr/local/directadmin/ and ./build exim_conf
If Exim does not restart automatically, use sudo systemctl restart exim. You can check if it is working correctly using sudo systemctl status exim

If this does not work for you, use the exim_post.sh hook provided in the tools/ folder.
🛠️ Create and install the protection script

Run these commands as root:

    Create the hooks directory (if it does not exist)
    bash

    mkdir -p /usr/local/directadmin/scripts/custom

    Copy the exim_post.sh file found in the tools folder of the support module.
    Or create the script file:
    bash

    nano /usr/local/directadmin/scripts/custom/exim_post.sh

    And paste the following content (copy the entire block):
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

    Save and exit (Ctrl+O, Enter, Ctrl+X).

    Give execution permissions:
    bash

    chmod +x /usr/local/directadmin/scripts/custom/exim_post.sh

    Test that it works (optional):
    bash

    /usr/local/directadmin/scripts/custom/exim_post.sh

    Then verify with:
    bash

    grep "auth_hit_limit_acl" /etc/exim.conf

    You should see the lines commented out with #.

With this, every time DirectAdmin regenerates the Exim configuration (by update or manually), the script will run automatically and keep the problematic rule deactivated.
Emails from iPhone arrive with strange characters

    The module already includes a sanitization system that decodes quoted-printable and converts to UTF-8. If it persists, verify that the sender is not using an uncommon charset.

🧑‍💻 Credits / Authors

    Lead Developer: [Víctor Fornés for Inversiones Forma SPA] (2026)

    Contributions: DeepSeek AI (2026)

The module is published under the GNU AGPL‑3.0 license.
☕ Support this project

If you find this module useful, you can buy me a coffee via PayPal:
https://paypal.me/inversionesformaspa
Any support is welcome! 🙏
🤝 Contributing

Contributions are welcome. Open an issue or a pull request in this repository.
📄 License

This module is distributed under the GNU AGPL‑3.0 license, compatible with the Apache 2.0 license of FOSSBilling.
