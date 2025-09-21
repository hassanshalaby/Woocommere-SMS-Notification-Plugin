# WooCommerce SMS Notifications by MasrBokra

## Description
WooCommerce SMS Notifications by MasrBokra is a powerful WordPress plugin that integrates SMS functionality into your WooCommerce store. It enables sending SMS notifications for various events such as new orders, order status changes, user registrations, one-time passwords (OTPs), and abandoned cart reminders. The plugin also includes features for broadcasting bulk SMS, customizable message templates, and a dashboard to manage sent messages with pagination and archive clearing capabilities.

## Features
- **SMS Notifications**: Send automated SMS for:
  - New user registrations (with OTP)
  - Order confirmations, processing, shipping, delivery, and cancellations
  - Payment OTP verification
  - Abandoned cart reminders
  - Password reset via SMS
- **Bulk SMS Broadcasting**: Send marketing messages to all verified users.
- **Customizable Messages**: Define custom message templates in Arabic and English for each notification type.
- **Dashboard**: View and manage sent messages with pagination, resend options, and archive clearing.
- **Low Balance Alerts**: Receive notifications when your SMS balance is low.
- **API Integration**: Connects with the MasrBokra SMS API for reliable message delivery.
- **Multilingual Support**: Supports Arabic and English interfaces with RTL support.
- **Cron Jobs**: Automated checks for abandoned carts and SMS balance.

## Requirements
- WordPress 5.0 or higher
- WooCommerce 3.0 or higher
- PHP 7.2 or higher
- A valid account with [MasrBokra SMS](http://shotbulksms.com) for API credentials

## Installation
1. Download the plugin ZIP file from the GitHub repository.
2. Log in to your WordPress admin dashboard.
3. Navigate to **Plugins > Add New** and click **Upload Plugin**.
4. Upload the ZIP file and click **Install Now**.
5. Activate the plugin through the **Plugins** menu in WordPress.
6. Go to **SMS Settings** in the WordPress admin menu to configure the plugin.

## Configuration
1. **API Credentials**:
   - Navigate to **SMS Settings > User Settings**.
   - Enter your MasrBokra SMS username, password, and sender name (obtained from [shotbulksms.com](http://shotbulksms.com)).
   - Save the settings to verify your credentials and check your SMS balance.
2. **Message Settings**:
   - Go to **SMS Settings > Message Settings**.
   - Enable/disable notification types (e.g., OTP for registration, order updates).
   - Customize message templates for Arabic and English.
3. **Marketing**:
   - Use the **Marketing** tab to send bulk SMS to verified users.
   - Ensure sufficient SMS balance before sending bulk messages.
4. **Low Balance Alerts**:
   - Enable low balance alerts in the **Low Balance Alert** tab to receive notifications when your SMS balance is depleted.
5. **Dashboard**:
   - Monitor sent messages, resend specific messages, or clear the archive from the **Dashboard** tab.

## Usage
- **Automatic Notifications**: Once configured, the plugin automatically sends SMS based on enabled triggers (e.g., new orders, user registrations).
- **Bulk SMS**: Use the **Marketing** tab to compose and send promotional messages to all users with verified phone numbers.
- **Message Archive**: View the history of sent messages in the **Dashboard** tab, with options to resend or clear the archive.
- **Testing**: To test the SMS API, append `?test_sms_api=1` to the WordPress admin URL (e.g., `https://your-site.com/wp-admin/?test_sms_api=1`) and check the error logs for results.

## API Integration
The plugin integrates with the MasrBokra SMS API (`http://sms.masrbokra.com/sendsms.php`). Ensure your API credentials are correct and that your server can make outbound HTTP requests to this endpoint. The plugin supports:
- GET requests for single messages or balance checks.
- Message splitting for texts exceeding the character limit (70 for Arabic, 160 for English).
- Debug logging (enable `SMS_DEBUG` for detailed logs).

## Debugging
- Enable `SMS_DEBUG` (set to `true` in the plugin code) to log API requests and responses to the WordPress debug log.
- Check the WordPress debug log (`wp-content/debug.log`) for errors related to API calls or SMS sending.
- Use the test API feature (`?test_sms_api=1`) to verify API connectivity and credentials.

## File Structure
- `index.php`: Main plugin file containing core functionality.
- `assets/sms.css`: Custom styles for the admin interface.
- `assets/sms.js`: JavaScript for handling AJAX requests and UI interactions.
- `languages/`: Translation files for multilingual support.

## Frequently Asked Questions
### How do I get MasrBokra SMS API credentials?
Sign up at [shotbulksms.com](http://shotbulksms.com) to obtain your username, password, and sender name.

### Why are SMS messages not being sent?
- Verify your API credentials in the **User Settings** tab.
- Check your SMS balance in the **User Settings** tab.
- Ensure the recipient phone number is valid and includes the country code.
- Review the WordPress debug log for errors if `SMS_DEBUG` is enabled.

### How can I customize SMS messages?
Go to **SMS Settings > Message Settings** to enable/disable notifications and edit message templates for Arabic and English.

### How does the abandoned cart reminder work?
The plugin uses a WordPress cron job (`masrbokra_check_abandoned_carts`) to check for carts in `checkout-draft` status older than 1 hour. If enabled, it sends a reminder SMS to the user's billing phone.

## Changelog
### 2.16
- Initial release with full SMS notification features, bulk SMS, and dashboard functionality.
- Added support for OTP verification, order status updates, and abandoned cart reminders.
- Implemented multilingual message templates and RTL support.

## License
This plugin is licensed under the [GPLv2 or later](https://www.gnu.org/licenses/gpl-2.0.html).

## Contributing
Contributions are welcome! Please submit pull requests or open issues on the [GitHub repository](https://github.com/your-repo/woocommerce-sms-notifications).

## Support
For support, open an issue on the [GitHub repository](https://github.com/your-repo/woocommerce-sms-notifications) or contact the MasrBokra team via [shotbulksms.com](http://shotbulksms.com).
