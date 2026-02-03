<div align="center">
  <img src="https://raw.githubusercontent.com/PteroCA-Org/panel/main/public/assets/img/logo/logo.png" alt="PteroCA Logo" width="400">

  <h1>PteroCA</h1>
  <p><strong>Professional Client Area & Billing Panel for Pterodactyl Hosting</strong></p>
  <p>Transform your Pterodactyl hosting into a complete SaaS business with automated billing, server provisioning, and a powerful plugin ecosystem.</p>

  <!-- Project Status -->
  ![Version](https://img.shields.io/github/v/tag/PteroCA-Org/panel?label=version&color=blue)
  ![Release Date](https://img.shields.io/github/release-date/PteroCA-Org/panel)
  ![License](https://img.shields.io/github/license/PteroCA-Org/panel)

  <!-- Technical Quality -->
  ![Build Status](https://img.shields.io/github/actions/workflow/status/PteroCA-Org/panel/symfony.yml?branch=main)
  ![PHP 8.2+](https://img.shields.io/badge/PHP-8.2%2B-777BB4?logo=php&logoColor=white)
  ![Dependencies](https://img.shields.io/librariesio/github/pteroca-com/panel)

  <!-- Community -->
  [![Discord](https://img.shields.io/discord/1330902668826382367?logo=discord&logoColor=white&label=Discord&color=5865F2)](https://discord.gg/Gz5phhuZym)
  ![GitHub Stars](https://img.shields.io/github/stars/PteroCA-Org/panel?style=social)
  ![Issues](https://img.shields.io/github/issues/PteroCA-Org/panel)

  <p>
    <a href="#-whats-new-in-v06">What's New</a> •
    <a href="#-see-it-in-action">Demo</a> •
    <a href="#-core-features">Features</a> •
    <a href="#-quick-start">Quick Start</a> •
    <a href="https://docs.pteroca.com">Documentation</a> •
    <a href="#-community--support">Community</a>
  </p>
</div>

---

## 🚀 What's New in v0.6

Version 0.6 introduces a plugin-first architecture that fundamentally changes how PteroCA can be extended and customized.

### Highlights

- 🔌 **Complete Plugin System** — first-class plugins with lifecycle management, security checks, and full framework access
- ⚡ **Event-Driven Architecture** — 245+ events covering forms, CRUD, emails, permissions, and widgets
- 🔐 **Granular Permissions** — 40+ fine-grained permissions with plugin-defined access control
- 🎨 **Universal Widget System** — context-aware UI extensions for dashboards, admin panels, and navigation
- 💳 **Payment Provider Extensibility** — built-in Stripe, PayPal via plugin, and custom providers
- 🎨 **Modern UI Refresh** — updated design, custom fonts, and full dark mode support

[View Full Changelog](https://github.com/PteroCA-Org/panel/releases) | [Documentation](https://docs.pteroca.com)

---

## 📺 See It In Action

<div align="center">
  <img src="https://2313594578-files.gitbook.io/~/files/v0/b/gitbook-x-prod.appspot.com/o/spaces%2F134rFblgKKOucO0ArkzV%2Fuploads%2Fgit-blob-be498df7f3f831c8f1a996dafa8b8deb4641d468%2Fpteroca.gif?alt=media" alt="PteroCA Demo - Login, Purchase, Server Management" width="480">
  <p><em>Complete workflow: User login → Server purchase → Real-time management (console, stats, controls)</em></p>
</div>

### Try the Live Demo

Experience PteroCA with full functionality:

- **URL:** [https://demo.pteroca.com](https://demo.pteroca.com)
- **Login:** `demo@pteroca.com`
- **Password:** `PterocaDemo`

**Note:** Editing features are restricted in the demo environment.

---

## ✨ Core Features

### Advanced Billing System
- **Flexible Pricing Models** - Time-based (hourly, monthly, yearly), usage-based (per-slot), and multi-period pricing with different rates for different durations
- **Automated Billing Cycles** - Automatic server suspension for non-payment, renewal reminders, and grace periods
- **Voucher System** - Balance top-up and discount codes with email verification
- **Payment Processing** - Stripe (built-in), PayPal (plugin), and extensible payment provider system

### Complete Server Management
- **Automated Provisioning** - Instant server creation via Pterodactyl API with customizable configurations and egg-based product templates
- **Real-Time Control Panel** - Live console access, server statistics (CPU, RAM, disk, network), and power controls
- **Advanced Features** - Database management, backup creation and restoration, port allocation, subuser management with permissions, schedule/task management, and startup variable configuration

### Plugin Ecosystem
- **Developer-Friendly** - Full Symfony integration with PSR-4 autoloading, Doctrine ORM support, and EasyAdmin CRUD generation
- **Security & Quality** - Automated security scanning, plugin health monitoring, dependency management, and capability-based permissions
- **Official Plugins** - Hello World Plugin, PayPal Payment Provider, and more in development

[Browse Plugin Documentation →](https://docs.pteroca.com/for-developers/plugins)

### Internationalization
- **14 Languages** - English, German, French, Spanish, Italian, Portuguese, Dutch, Polish, Russian, Ukrainian, Chinese, Hindi, Indonesian, Swiss German

### Enterprise Security
- **Permission-Based Access Control** - 40+ granular permissions with role-based management and plugin-specific permissions
- **Security Features** - CSRF protection, XSS prevention, SQL injection safeguards, and trusted proxy support

### Theming & Customization
- **Built-in Theme System** - Default responsive theme with dark/light mode support and custom CSS/JS injection
- **Extensible Templates** - Twig-based engine with view overrides and widget extension points

[View All Features →](https://docs.pteroca.com)

---

## 🚀 Quick Start

### Installation Options

Choose the method that works best for you:

#### Automatic Installer (Recommended)
```bash
curl -sSL https://pteroca.com/installer.sh | bash
```
Perfect for production deployments. Handles all dependencies automatically.

[Automatic Installation Guide →](https://docs.pteroca.com/installation/installation/automatic-installation)

#### Docker Compose (Fastest)
```bash
git clone https://github.com/PteroCA-Org/panel.git pteroca
cd pteroca
docker-compose up -d
```
Ideal for development and testing environments.

[Docker Installation Guide →](https://docs.pteroca.com/installation/installation/docker-installation)

#### Manual Installation
For custom environments or advanced configurations.

[Manual Installation Guide →](https://docs.pteroca.com/installation/installation/manual-installation)

### Requirements

| Component | Requirement |
|-----------|-------------|
| **PHP** | 8.2+ with extensions: `cli`, `ctype`, `iconv`, `mysql`, `pdo`, `mbstring`, `tokenizer`, `bcmath`, `xml`, `curl`, `zip`, `intl`, `fpm` (NGINX) |
| **Database** | MySQL 5.7.22+ (MySQL 8 recommended) or MariaDB 10.2+ |
| **Web Server** | NGINX or Apache |
| **Pterodactyl** | v1.11+ (compatible with latest versions) |
| **Tools** | Git, Composer 2, cURL, tar, unzip |

### Next Steps

After installation, configure your instance:

1. Run the setup wizard at `https://your-domain.com/first-configuration` or use `php bin/console pteroca:system:configure`
2. Configure Pterodactyl API connection
3. Set up your first payment provider
4. Create product categories and offerings

[Complete Setup Guide →](https://docs.pteroca.com/quick-start/quick-start/minimal-configuration)

---

## 🔌 Plugin System

**Plugins are first-class citizens in PteroCA** — not extensions bolted onto the core, but a foundational architecture designed for extensibility from day one.

Extend PteroCA with custom functionality through the comprehensive v0.6 plugin system.

### Why Plugins?

- **Zero Core Modifications** - Extend functionality without touching core code
- **Full Framework Access** - Leverage Symfony, Doctrine, Twig, and EasyAdmin
- **Event-Driven Hooks** - 245+ events to tap into every system action
- **Professional Tools** - Security scanning, health monitoring, dependency management

### Plugin Marketplace

**Browse and install plugins directly from the [PteroCA Marketplace](https://marketplace.pteroca.com)** - A centralized hub where you can:
- 🔍 **Discover** - Browse curated themes and plugins built by the community
- ⬇️ **Download** - Install plugins and themes with a single click
- 📤 **Publish** - Share your own creations with the PteroCA community

[Visit the Marketplace →](https://marketplace.pteroca.com)

### Plugin Capabilities

| Capability | Use Cases |
|------------|-----------|
| **Routes** | Payment providers, custom pages, webhooks |
| **Entities** | Store plugin data, extend user profiles |
| **Widgets** | Dashboard widgets, admin panels, custom UI |
| **Events** | Webhook integrations, automation, custom logic |
| **Console** | Maintenance tasks, data migration, automation |
| **Cron** | Scheduled tasks, periodic cleanups, reports |

### Official Plugin Examples

- **[Example Hello World Plugin](https://marketplace.pteroca.com/product/example-hello-world-plugin)** - Comprehensive example demonstrating all plugin capabilities
- **[PayPal Payment Provider](https://marketplace.pteroca.com/product/paypal-payment-provider)** - Payment gateway integration

[Plugin Development Guide →](https://docs.pteroca.com/for-developers/plugins/getting-started) | [Plugin API Reference →](https://docs.pteroca.com/for-developers/plugins)

---

## 🤝 Community & Support

<table>
  <tr>
    <td align="center" width="25%">
      <h3>💬 Discord</h3>
      <a href="https://discord.gg/Gz5phhuZym">
        <img src="https://img.shields.io/discord/1330902668826382367?logo=discord&style=for-the-badge&color=5865F2" alt="Discord">
      </a>
      <p>Get help, share ideas, and connect with the community</p>
    </td>
    <td align="center" width="25%">
      <h3>📚 Documentation</h3>
      <a href="https://docs.pteroca.com">
        <img src="https://img.shields.io/badge/Docs-pteroca.com-blue?style=for-the-badge" alt="Docs">
      </a>
      <p>Comprehensive guides, tutorials, and API references</p>
    </td>
    <td align="center" width="25%">
      <h3>🐛 Issues</h3>
      <a href="https://github.com/PteroCA-Org/panel/issues">
        <img src="https://img.shields.io/github/issues/PteroCA-Org/panel?style=for-the-badge" alt="Issues">
      </a>
      <p>Report bugs and request features</p>
    </td>
    <td align="center" width="25%">
      <h3>🗺️ Roadmap</h3>
      <a href="https://pteroca.com/roadmap">
        <img src="https://img.shields.io/badge/Roadmap-View-green?style=for-the-badge" alt="Roadmap">
      </a>
      <p>See what's coming next</p>
    </td>
  </tr>
</table>

### Ways to Support PteroCA

**⭐ Star this Repository** - The #1 way to support us! Stars help us reach more users and validate our work.

**💖 Sponsor Development** - Support ongoing development through:
- [GitHub Sponsors](https://github.com/sponsors/pteroca-com)
- [Buy Me a Coffee](https://www.buymeacoffee.com/pteroca)
- [Ko-fi](https://ko-fi.com/pteroca)

> 🎁 **Sponsor Perks:** Supporters get a special Discord role + access to a priority support channel.

### Sponsors 💜

<a href="https://github.com/lostchunks">
  <img src="https://images.weserv.nl/?url=github.com/lostchunks.png&h=60&w=60&fit=cover&mask=circle" alt="lostchunks" />
</a>
<a href="https://github.com/TheRose97">
  <img src="https://images.weserv.nl/?url=github.com/TheRose97.png&h=60&w=60&fit=cover&mask=circle" alt="TheRose97" />
</a>

---

## 🗺️ Roadmap

PteroCA is actively developed with a clear roadmap and regular releases.

**Vote on features and track progress:**
- [Official Roadmap Page](https://pteroca.com/roadmap)

**Have an idea?** [Submit a feature request →](https://github.com/PteroCA-Org/panel/issues/new?labels=enhancement)

---

## 🛠️ Contributing

We welcome contributions from developers of all skill levels!

### Get Started

1. **Join our Discord** and request the **Developer** role for access to exclusive development channels
2. **Review our guidelines** - [Contributing Guide](CONTRIBUTING.md) and [Code of Conduct](CODE_OF_CONDUCT.md)
3. **Pick an issue** - Check [Good First Issues](https://github.com/PteroCA-Org/panel/labels/good%20first%20issue)

### Contribution Areas

- **Code** - Features, bug fixes, refactoring
- **Documentation** - Guides, tutorials, API docs
- **Translations** - Add or improve language files
- **Plugins** - Build and share community plugins
- **Testing** - Write tests, report bugs
- **Design** - UI/UX improvements, themes

[Read the Contributing Guide →](CONTRIBUTING.md)

---

## 📄 License

PteroCA is open-source software licensed under the [MIT License](LICENSE).

**TL;DR:** Free to use, modify, and distribute, even commercially. See [LICENSE](LICENSE) for full terms.

---

## 🙏 Acknowledgments

- Built on the excellent [Pterodactyl Panel](https://pterodactyl.io/)
- Powered by [Symfony Framework](https://symfony.com/)
- Admin interface by [EasyAdmin Bundle](https://github.com/EasyCorp/EasyAdminBundle)

---

<div align="center">
  <p>Made with ❤️ by the PteroCA team and contributors</p>
  <p>
    <a href="https://pteroca.com">Website</a> •
    <a href="https://docs.pteroca.com">Documentation</a> •
    <a href="https://discord.gg/Gz5phhuZym">Discord</a> •
    <a href="https://github.com/PteroCA-Org/panel">GitHub</a>
  </p>

  <p>
    <strong>⭐ If you find PteroCA useful, please consider giving us a star! ⭐</strong>
  </p>
</div>
