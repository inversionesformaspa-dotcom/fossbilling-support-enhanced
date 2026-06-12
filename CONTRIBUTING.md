# Contributing to FOSSBilling Support Module (Enhanced)

First of all, thank you for checking out our enhanced support module! This project was developed by **Inversiones Forma SPA** to solve critical enterprise-grade mail routing and anti-spam needs in production hosting environments.

As a lean commercial team, **our development time is highly limited**. To maintain this project without burning out, we kindly ask all users and contributors to respect the following guidelines before opening issues or submitting code.

---

## 🛑 Support Policy & Scope

> [!WARNING]
> **This is an open-source project provided "as is". We do not offer free technical support, installation assistance, or server troubleshooting via GitHub Issues, email, or private messages.**

If you encounter an issue, please verify that it is directly caused by this module's code and is not a misconfiguration of your MTA (Exim/Postfix), MDA (Dovecot), or hosting panel (DirectAdmin/cPanel/Plesk).

---

## 🐛 Reporting Bugs

If you find a legitimate bug in the module, please open an **Issue** using a clear structure. To save us time, every bug report **MUST** include:

1. **Environment Details:** FOSSBilling version, PHP version, and Web Panel used (DirectAdmin, cPanel, Plesk, or standalone).
2. **Mail Infrastructure:** Mail server stack (e.g., Exim + Dovecot + Sieve) and whether you are using SpamAssassin.
3. **Logs:** Relevant error logs from `data/log/php_error.log` or your system MTA logs. **Remove sensitive data (passwords, domain names, IPs) before pasting.**
4. **Steps to Reproduce:** A concise list of steps to trigger the bug.

*Issues that fail to provide logs or clear steps to reproduce may be closed automatically to protect engineering time.*

---

## 💡 Feature Requests

We built this module to cover high-end hosting integration requirements. If you have an idea for a new feature:
* Search the existing Issues to make sure it hasn't been requested before.
* Open a new Issue describing the specific use case and why it benefits enterprise environments.
* Please understand that we may decline features that do not align with our internal production roadmaps or that demand excessive maintenance time.

---

## 🤝 Submitting Pull Requests (PRs)

We welcome community patches, optimization improvements, and bug fixes! To ensure a smooth review process:

1. **Fork the Repository:** Create a branch for your fix or improvement (e.g., `fix/sieve-socket-timeout`).
2. **Keep it Focused:** Do not combine multiple unrelated fixes into a single PR.
3. **Coding Standards:** Ensure your code is clean, well-commented, and compatible with PHP 8.3+ and FOSSBilling 0.8.2+.
4. **License Agreement:** By submitting a Pull Request, you agree that your contributions will be licensed under the same **GNU AGPL‑3.0 License** as the rest of the project.

---

## ☕ Support Our Work

If this module saves your hosting company hours of configuration or troubleshooting, consider supporting our efforts so we can keep allocating resources to maintain it:

👉 **[Support Inversiones Forma SPA via PayPal.Me](https://paypal.me/inversionesformaspa)**

Thank you for your cooperation and for keeping the open-source ecosystem respectful of developers' time!
