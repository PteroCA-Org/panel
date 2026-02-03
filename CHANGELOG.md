# Changelog

## [0.6.3] - 2026-02-03

### Added
- Added comprehensive theme management system with upload, copy, export, and delete functionality.
- Added 8 new granular permissions for theme operations (access, view, set default, configure, upload, copy, export, delete).
- Added template context system - separate theme configuration for panel, landing page, and email contexts.
- Added new customizable landing page with featured categories, products, and widget system.
- Added ability to choose specific Pterodactyl node during server purchase.
- Added product variant system for linking products with different node configurations.
- Added real-time node availability checking via new API endpoint.
- Added admin server creation functionality - admins can create servers for users directly from admin panel.
- Added `plugin:uninstall` console command with dependency checking and file removal options.
- Added delete plugin action in plugin details view with dependency validation.
- Added plugin reset functionality for recovery from faulted state.
- Added user Pterodactyl Client API key regeneration feature.
- Added soft delete functionality for categories.
- Added option to delete servers immediately upon expiry instead of waiting for cleanup job.
- Added localhost allocation support for server creation.
- Added sponsors section to README with sponsor perks information.
- Added `dev:upgrade-theme` command for theme system migrations.
- Added `create_server` permission for admin server creation access control.

### Changed
- Improved voucher system with separated validation and redemption logic.
- Enhanced voucher UI with context-aware error messages and type explanations.
- Changed MB to MiB in product settings with correct binary unit value calculation.
- Changed resource rounding from `round()` to `ceil()` for more accurate calculations.
- Improved unit rounding unification across all resource calculations.
- Replaced manual password validation with Symfony's `RepeatedType` form field.
- Updated default logo and removed filter styles.
- Improved icons on server renewal page.
- Updated .gitignore with theme system directories.
- Enhanced plugin CRUD controller with improved dependency management and state handling.
- Improved node selection service with automatic best-fit allocation algorithm.
- Updated landing page with modern dark theme styling and responsive design.

### Fixed
- Fixed cache bug in TemplateManager where template metadata was loaded from disk on every call.
- Fixed voucher application issues where wrong voucher types could be applied on incorrect pages.
- Fixed soft delete handling for vouchers.
- Fixed voucher values to allow decimal/float amounts instead of integers only.
- Fixed networking graph to show rate of bytes (bytes/second) instead of cumulative total.
- Fixed cache clearing notification not appearing correctly after system updates.
- Fixed first free allocation search by removing 100 allocation limit.
- Fixed first egg installation issues during server creation.
- Fixed password recovery functionality that was failing due to validation errors.
- Fixed server reinstallation by correcting egg build preparation.
- Fixed login page mobile scrolling issues.
- Fixed plugin dependencies validation environment variable passing.
- Fixed routing issue in theme-related pages.

---

## [0.6.2] – 2025-12-28

### Added
- Added “Allow Auto Renewal” option for products.
- Added “Test Pterodactyl Connection” button in Settings → Pterodactyl.
- Added loading spinners to authentication pages (login, register, password reset).
- Added server IP display hierarchy (public → internal → `0.0.0.0`).
- Added direct Pterodactyl server redirect button in Admin Product CRUD.

### Changed
- Refactored and redesigned email templates with fully new styling.
- Updated settings hints to be more user-friendly and descriptive.
- Improved CRUD action notifications – every action now shows proper feedback.
- Improved telemetry logging reliability.
- Improved CSS styling and overall UI consistency.
- Completely restyled the Terms of Service page.

### Fixed
- Fixed `set as empty` option handling in settings (added JS field validation).
- Fixed product option sorting on product pages.
- Fixed handling of selected option argument on server configuration page.
- Fixed unwanted input visibility on product/server configuration page.
- Fixed plugin state handling when plugin files are removed (plugin is now automatically marked as disabled in the database).

---

## [0.6.1] - 2025-12-21

### Added
- Added automatic plugin dependency installation on plugin enable.
- Added product and category priority support.
- Added missing Indonesian language enum.

### Changed
- Improved product egg sanitization.
- Optimized product validation logic.
- Improved server management handling.
- Allowed servers without assigned products (post-migration).
- Minor UI and CSS adjustments.

### Fixed
- Fixed duplicated server purchase emails.
- Fixed 500 error when loading server databases.
- Fixed EULA acceptance on server management page.
- Fixed various server management edge cases.

---

## [0.6.0] - 2025-12-16

### Added
- Introduced a comprehensive Plugin System with full lifecycle management (scan, enable, disable, update, reset, uninstall).
- Added capability-based plugin access control for routes, entities, migrations, UI components, events, console commands, and cron tasks.
- Added plugin security validator that scans for dangerous code patterns, SQL injection risks, and path traversal vulnerabilities.
- Added plugin health check system with automated monitoring and audit logging.
- Added plugin dependency management with semantic versioning support and circular dependency detection.
- Added plugin upload functionality with ZIP archive support, security pre-scanning, and automatic installation.
- Added universal widget system supporting dashboard, admin, and navbar widgets with priority-based positioning.
- Added EasyAdmin CRUD controller support for plugins - plugins can now create CRUD interfaces for their entities that integrate seamlessly with the panel's admin interface.
- Added 40+ new event classes for event-driven architecture across multiple domains (forms, views, menus, emails, permissions, CRUD operations, widgets).
- Added 10+ new console commands for plugin management (`plugin:scan`, `plugin:enable`, `plugin:disable`, `plugin:reset`, `plugin:list`, `plugin:info`, `plugin:health`, `plugin:security-scan`, `plugin:dependencies`, `plugin:rebuild-cache`).
- Added navbar widget extension points for plugins to extend the navigation bar.
- Added comprehensive plugin development documentation including API reference, security best practices, testing guide, and troubleshooting guide.
- Added `pterodactyl_root_admin` permission to control root admin access in Pterodactyl Panel - provides flexible control independent of PteroCA admin role.
- Added granular edit permissions for Settings CRUD (`edit_settings_general`, `edit_settings_email`, `edit_settings_payment`, `edit_settings_pterodactyl`, `edit_settings_security`, `edit_settings_theme`, `edit_settings_plugin`) - separated viewing from editing permissions for better access control.
- Added plugin-specific permissions system - plugins can define custom permissions in their manifest that are automatically registered and available for role assignment.
- Added telemetry system for anonymous usage statistics and crash reporting (opt-in, fully transparent, can be disabled).
- Added Indonesian language support (thanks to the community contributors).
- Added product validation rules and improved product configuration interface.
- Added server migration command improvements with better error handling and progress reporting.
- Added support for Inter and Poppins custom fonts in the default theme.

### Changed
- Completely redesigned and refreshed the entire frontend with modern design, improved color schemes, and enhanced user experience.
- Upgraded to EasyAdminBundle v4.27.5 with full compatibility fixes for new API changes (Twig components, trans filter syntax, AdminContext methods).
- Standardized button styling system - replaced Bootstrap's verbose button classes (`btn-success`, `btn-danger`, `btn-primary`) with simplified custom classes (`success`, `danger`, `primary`, `secondary`, `warning`, `info`) for better maintainability and consistency across templates.
- Rewrote Pterodactyl API communication implementation with custom API layer for improved reliability and maintainability - completely new adapter architecture for both Application and Client APIs.
- Refactored all CLI command names to use consistent `pteroca:` namespace with hierarchical organization (e.g., `pteroca:user:*`, `pteroca:server:*`, `pteroca:plugin:*`). Old command names remain available as deprecated aliases until v1.0.0.
- Completed migration from legacy role system to new permission-based access control - replaced all hardcoded `ROLE_ADMIN` checks with granular permission checks (`access_admin_overview`, `access_servers`, `edit_server`, etc.). Updated 14 controllers, 3 services, and CLI commands to use the new `hasPermission()` method and RoleManager service.
- Removed backward compatibility layer for legacy role system - removed `UserRoleEnum` enum and legacy JSON roles fallback. All users now use the database-driven role and permission system exclusively.
- Refactored Pterodactyl admin status determination - replaced `isAdmin()` method with permission-based check using new `pterodactyl_root_admin` permission.
- Payment providers now define their own callback routes and URL building logic via interface methods - refactored payment callback system to be provider-driven instead of hard-coded. Generic callback routes `/wallet/{provider}/success` and `/wallet/{provider}/cancel` work for any payment provider.
- Plugin service loading now respects enabled/disabled state - implemented `EnabledPluginsCacheManager` to track enabled plugins in cache file. Only enabled plugins have their services registered in Symfony container during compilation, preventing disabled plugins from registering providers or services.
- Migrated event subscriber, console command, and cron task registration from compile-time to runtime for better plugin support and dynamic loading.
- Redesigned first-time configuration wizard with visual progress stepper, improved layout, and enhanced user experience.
- Improved cache:clear command performance by optimizing compiler passes.
- Enhanced form system with generic events for plugin extensibility.
- Updated email system with before/after send events for plugin hooks.
- Plugin settings now use config_schema as single source of truth - settings are defined declaratively in plugin.json and automatically created when plugin is enabled.
- Added automatic type mapping from config_schema types to UI field types - plugin config_schema types (string, integer, boolean, float, json, array) are automatically mapped to appropriate SettingTypeEnum display types.
- Improved error page templates with better styling and user-friendly messages.
- Updated flash message system to use new Twig component syntax and improved styling.
- Enhanced server management page styling and user interface.
- Updated cart configurator with improved product ordering calculator.
- Improved egg manager interface and validation.

### Fixed
- Fixed EasyAdminBundle v4.27.5 compatibility issues:
  - Fixed `Call to undefined method MenuItemDto::getAsDto()` error in DashboardController.
  - Fixed `hasContext` method not existing in AdminContext - updated to use `null != ea` check.
  - Updated flash_messages.html.twig template to use new Twig component syntax.
  - Fixed trans filter syntax from `domain =` to `domain:` throughout templates.
- Fixed disabled plugins still registering services - disabled plugins no longer have their payment providers or other services available in the container.
- Fixed button visibility issues - server control buttons (Start/Stop/Restart) and plugin action buttons now display with correct colors and styling.
- Plugin settings with 'string', 'integer' types now render correctly in UI - fixed type validation errors during plugin scanning and empty Type column display in settings UI.
- Fixed PayPal payment provider callback URL handling - each provider now defines its own callback URL format through interface methods.
- Added missing `access_server_products` permission to migration and assigned to admin role - fixes "You don't have enough permissions" error when accessing ServerProductCrudController.
- Refactored API permission checking to use granular permissions instead of deprecated `access_admin_api`.
- Fixed suspended servers listing and display issues.
- Fixed server migration command bugs and edge cases.
- Fixed product validation and configuration issues.
- Various performance optimizations and bug fixes across the system.

### Deprecated
- Routes `stripe_success` and `stripe_cancel` are deprecated and will be removed in v0.7.0. Use provider-specific callback routes (`payment_callback_success`, `payment_callback_cancel`) instead.

### Removed
- Removed `isAdmin()` method from User entity and UserInterface - use `hasPermission('pterodactyl_root_admin')` instead.

---

## [0.5.11] - 2025-10-26

### Added
- Added new app:cleanup-purchase-tokens command to automatically remove expired purchase tokens - it is now included in the default cron job and runs once per hour.
- Extended app:cron-job-schedule command with conditional execution logic - now also responsible for running the inactive server cleanup every hour (separate from the suspension job, which still runs every minute).

### Fixed
- Fixed registration issue for users signing up again after their previous account was deleted.
- Fixed admin permissions for viewing the server dashboard.
- Secured the server purchase and renewal process - added CSRF token validation, purchase token verification, and database transaction locking to prevent multiple or duplicate server creation caused by repeated clicks or browser navigation actions.

---

## [0.5.10] - 2025-10-12

### Added
- Added automatic Docker installation script - available at [pteroca.com/scripts/docker_installer.sh](https://pteroca.com/scripts/docker_installer.sh).  
- Integrated Docker-based installation method into daily automated test runs to ensure long-term reliability and prevent future breakages.  

### Changed
- Updated entire installation documentation section to reflect new Docker setup method.  
- Improved updater process - enhanced dependency installation handling via Composer and improved permission management during updates.  
- Updated `README.md` with latest setup and contribution instructions.  

### Fixed
- Fixed server name display - the user-defined server name is now shown consistently across the panel.  
- Fixed special character escaping in the Egg Manager.  
- Fixed Docker architecture - installation via Docker now works flawlessly.  
- Fixed HTTP 500 error occurring when viewing email logs with non-empty metadata in Email Log CRUD.  

---

## [0.5.9] - 2025-10-05

### Added
- Added missing translations to improve localization coverage.  

### Changed
- Improved project configurator and prepared groundwork for the upcoming all-in-one installation support.  

### Fixed
- Fixed pipeline responsible for detecting missing translations.  

---

## [0.5.8] - 2025-09-24

### Added
- Added validation for top-up amounts to prevent invalid values.  
- Added soft delete support for servers.  
- Added command for removing old system logs.  

### Changed
- Updated system updater to further improve reliability.  
- Rewritten logic for post-purchase and renewal emails, with minor template improvements.  
- Rewritten logic for user account activation to ensure proper functionality.  
- General code improvements and optimizations.  

### Fixed
- Fixed issue when accessing the page of a suspended server, which previously resulted in an error.  

---

## [0.5.7] - 2025-09-04

### Added
- Added new per-slot pricing type with egg configuration support.  
- Added server software tile on the server management page.  
- Added automatic Copy IP Address button on the server management page.  
- Added SMTP connection test button on the email settings page.  
- Added support for the `TRUSTED_HOSTS` variable for Symfony host validation.

### Changed
- Improved server IP display: the correct allocation is now shown even when a domain is assigned.  
- Rewritten email logic for server purchase and renewal notifications.  
- Improved session configuration for better compatibility with Cloudflare.  

### Fixed
- Fixed issue where administrators could not update server egg variables.  
- Fixed bug causing suspended servers not to appear in the user’s server list.  

---

## [0.5.6] - 2025-08-15

### Added
- Added ability to copy products in the admin panel.
- Added EULA detection: when a server requires EULA acceptance after reinstallation, a modal with an Accept EULA button is displayed.
- Added PteroCA Addon for Pterodactyl version check on the admin dashboard.
- Added `pteroca:sync-servers` command to automatically remove servers no longer present in Pterodactyl.

### Changed
- Updated README.md file.
- Slight upgrade to the update command with improved local change protection, better repository fetching, enhanced scenario handling, and clearer error messages.

### Fixed
- Fixed grid layout issue in the My Servers section.

---

## [0.5.5] - 2025-07-27

### Added
- Added server status indicators (e.g. online/offline) to the server list views.
- Displayed informative messages when a server is currently installing or reinstalling.

### Changed
- Improved validation, error handling, and instructional messages in both the Web Installer and CLI installer.
- Moved Categories and Products under a new collapsible Store section in the sidebar for improved navigation.

---

## [0.5.4] - 2025-07-13

### Added
- Added support for server subusers with permission management.
- Added server schedule management, including per-product/server schedule limits.
- Added CPU pinning support for both products and servers.
- Added missing translations across the panel.
- Added docker-compose.yml for both development and production environments to enable quick setup via a single command.

### Changed
- Improved the admin user management CRUD interface.
- Improved logging of certain user actions.
- Various minor performance and optimization improvements.

---

## [0.5.3] - 2025-06-02

### Added
- Added command to migrate servers from Pterodactyl to PteroCA.
- Added user account verification check before allowing voucher usage.
- Added server IP address and current expiration date to the server renewal view.
- Added my account tab with payment history and account settings.

### Changed
- Renamed the Servers menu item to My servers for improved clarity.
- Refactored some code to improve readability and maintainability.
- Improved styling of input placeholders.

### Fixed
- Fixed an issue where activating a voucher to top up balance did not work correctly.
- Fixed an error that occurred when deleting a server from the PteroCA panel.
- Fixed missing translations in the Server Logs section.

---

## [0.5.2] - 2025-05-12

### Added
- Added command to migrate servers from Pterodactyl to PteroCA.
- Added user account verification check before allowing voucher usage.
- Added server IP address and current expiration date to the server renewal view.
- Added my account tab with payment history and account settings.

### Changed
- Renamed the Servers menu item to My servers for improved clarity.
- Refactored some code to improve readability and maintainability.
- Improved styling of input placeholders.

### Fixed
- Fixed an issue where activating a voucher to top up balance did not work correctly.
- Fixed an error that occurred when deleting a server from the PteroCA panel.
- Fixed missing translations in the Server Logs section.

---

## [0.5.1] - 2025-05-07

### Fixed
- Fixed a bug on the balance top‑up page that threw an error whenever the entered amount exceeded the available balance.

---

## [0.5.0] - 2025-05-05

### Added
- Added server port (allocation) management interface.
- Added server backup management functionality.
- Added server database management functionality.
- Added support for assigning different prices to different billing periods.
- Added order finalization page with product configurator.
- Added voucher system (balance top-up and discount codes).
- Added the ability to edit the build configuration of existing servers.
- Added support for Dutch (`nl`) and Swiss German (`de_CH`) languages.  
  Thanks to @ninin06 and @brainshead from Crowdin.
- Added new filters to CRUD listings.
- Added deprecation notices limited to `dev` environment only.

### Changed
- Updated color scheme with minor visual improvements.
- Updated `README.md` with latest project information.

### Fixed
- Fixed a JavaScript error on the server variable edit page that occurred in certain language versions.
- Fixed an issue where incorrect allocation count was set during server creation.

---

## [0.4.4] - 2025-03-26

### Added
- Added TRUSTED_PROXIES environment variable to specify trusted proxies for Symfony.
- Added DISABLE_CSRF environment variable to allow disabling CSRF protection.

### Fixed
- Fixed an issue where the user with USER_ROLE could not access server console.

---

## [0.4.3] - 2025-03-24

### Added
- Introduced a new `app:update-system` command to automate project updates.
- Added configuration options to enable/disable dark mode and specify a default mode.

### Changed
- Refined default theme colors for dark mode.
- Minor CSS changes in the default theme for improved layout consistency.

### Fixed
- Resolved an issue preventing emails from being sent.
- Addressed a bug that caused Stripe payments to fail.

---

## [0.4.2] - 2025-03-16

### Added
- Introduced a web wizard for the initial setup. The CLI setup remains available as an alternative.
- Added a CLI notification when attempting to create a user with an email address that already exists in Pterodactyl.

### Changed
- The Pterodactyl Client API Key is no longer required to access server management pages.

### Fixed
- Resolved an issue with logging in the production environment.

---

## [0.4.1] - 2025-03-10

### Added
- Added titles to all pages.
- Implemented a loading spinner when sending requests on the server management page.
- Added a confirmation prompt in the user creation command for creating a user without a Pterodactyl Client API key.
- Added database indexes to improve performance.

### Changed
- Updated UI styling.
- Redesigned the product creation page.
- Modified theme loading behavior to fall back to the default view if a custom theme view is not found.

### Fixed
- Refactored and cleaned up the codebase.
- Fixed JavaScript console errors.
- Resolved responsive design issues on the servers list page.
- Fixed styling issues in the responsive header.
- Updated functions to remove deprecated dependencies.
- Resolved issues with server activity logs details layout.

---

## [0.4.0] - 2025-02-20

### Added
- Descriptions are now displayed for each setting when editing.
- Automatic server renewal has been introduced.
- Users can now upload an avatar in profile settings.
- A configuration option has been added to specify the number of days after which suspended (unpaid) servers are removed.
- SSO login support has been implemented for the Pterodactyl plugin.
- An option to enable or disable SSO login for the Pterodactyl panel has been introduced.
- Custom templates can now be created and uploaded.
- Several buttons in the default template now feature icons.
- A command for generating new templates has been added.
- Hindi has been added as a supported language.

### Changed
- The Settings tab has been redesigned and is now divided into five categories.
- Settings are now displayed in a structured order.
- JavaScript files controlling the panel’s behavior have been moved into dedicated JS files, making them dependent on the selected template.

### Fixed
- Missing translations have been added.

---

## [0.3.3] - 2025-02-10

### Changed
- Default color scheme is now set to light mode.

### Fixed
- Fixed cache issue in the app:configuration-system command.

---

## [0.3.2] - 2025-02-02

### Added
- Added a new command to change the user's password via CLI.
- Added a new language: Russian (thanks to @Futuraura for the translation).
- Added discord link in the admin overview page (as a support link).

### Changed
- Improved error handling during user registration.

### Fixed
- Cache issue with the current version of the application.

---

## [0.3.1] - 2025-01-28

### Added
- Added a role confirmation prompt to the user creation command.
- Added banner files to products.
- Added validation rules for updating server variables.

### Changed
- Improved error handling during user creation via CLI.
- Enhanced error handling when updating server variables.
- Updated translations for the "Add Balance" button.
- Revised the README.md file.

### Fixed
- Fixed an issue where missing images caused empty spaces to appear.
- Resolved a bug with email translations.
- Blocked access to the registration page for logged-in users.
- Corrected a layout issue by separating product images from banners.

---

## [0.3.0] - 2025-01-12

### Added
- Product egg configuration with startup variable permissions
- Server management page with server console, statistics, startup configuration, and activity logs
- Admin panel for server management
- Update check functionality on the admin overview page
- Data synchronization command
- Support for PteroCA plugin for Pterodactyl
- Admin management panel for server logs

### Changed
- Updated Composer dependencies
- The text allowing egg changes after product purchase on the product page now depends on product settings

### Fixed
- Caching issue with system settings
- Empty space on product page when no image is provided
- Grid layout issue on categories page

---

## [0.2.4] - 2024-11-25

### Changed
- Updated README.md with new demo credentials and additional information.
- Revised project documentation to include more comprehensive and useful details.
- Removed the "Edit Log" option from the log details view to simplify navigation and improve usability.
- Adjusted default database configurator variables (DB_HOST, DB_NAME, DB_USER) for a more streamlined setup experience.

---

## [0.2.3] - 2024-11-19

### Added
- Added Admin Dashboard with statistics & useful information.

### Fixed
- Fixed route name in url.

---

## [0.2.2] - 2024-09-09

### Added
- Added url to login page on the registration page.
- Added terms of service page.
- Added css files versioning based on the application version.

### Changed
- Refactored the enums.
- Split store template into multiple files.
- Moved css from the templates to the css file.
- Set default settings as a migration.

---

## [0.2.1] - 2024-09-08

### Added
- One main queue worker for all the queues.
- Deleting old expired servers from the database (after 3 months).
- Added created at and updated at timestamps to the user table.

### Changed
- Updated md files with proper email address and version.
- CRUDs are now sorted by the created date.
- Category is not necessary for the product creation.
- Changed placeholder images to local urls.
- Changed login and register document titles.
- Split large template views into components.
- Updated project information in the composer.json file.

### Fixed
- Fixed the issue with viewing price in the renewal product page.

---

## [0.2.0] - 2024-09-02

### Added
- Introduced unit and integration tests.
- Introduced PHPStan for static analysis.
- Introduced CI workflow for automated testing.
- Added CHANGELOG.md file.

### Changed
- Refactored and cleaned up a significant portion of the codebase.
- Updated the README.md file with more information.

### Fixed
- Bug fixes related to application logic.
- Dashboard template loading server data issue fix.

---

## [0.1.1] - 2024-08-28

### Fixed
- Minor css loading issue fix.

---

## [0.1.0] - 2024-08-27

### Added
- Initial release of the application with basic functionality.