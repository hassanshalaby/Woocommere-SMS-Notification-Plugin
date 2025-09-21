<?php
/**
 * Plugin Name: WooCommerce SMS Notifications by MasrBokra
 * Description: Sends SMS for new orders, status changes, user registrations, OTPs, and abandoned cart reminders. Includes API endpoint, broadcast SMS, message customization, and a dashboard for sent messages with pagination and archive clearing.
 * Version: 2.16
 * Author: MasrBokra
 */

if (!defined('ABSPATH')) exit;

define('SMS_DEBUG', true);
define('SMS_OPTION_USER', 'masrbokra_sms_user');
define('SMS_OPTION_PASS', 'masrbokra_sms_pass');
define('SMS_OPTION_SENDER', 'masrbokra_sms_sender');
define('SMS_OPTION_NOTIFICATIONS', 'masrbokra_sms_notifications');
define('SMS_OPTION_LANGUAGE', 'masrbokra_sms_language');
define('SMS_OPTION_MESSAGES', 'masrbokra_sms_messages');
define('SMS_OPTION_ZERO_BALANCE_NOTIFIED', 'masrbokra_zero_balance_notified');
define('SMS_OPTION_LOW_BALANCE_ALERT', 'masrbokra_low_balance_alert');
define('SMS_OPTION_SENT_MESSAGES', 'masrbokra_sent_messages');

// Load WordPress Cron
register_activation_hook(__FILE__, function () {
    if (!wp_next_scheduled('masrbokra_check_abandoned_carts')) {
        wp_schedule_event(time(), 'hourly', 'masrbokra_check_abandoned_carts');
    }
    if (!wp_next_scheduled('masrbokra_check_balance')) {
        wp_schedule_event(time(), 'daily', 'masrbokra_check_balance');
    }
});
register_deactivation_hook(__FILE__, function () {
    wp_clear_scheduled_hook('masrbokra_check_abandoned_carts');
    wp_clear_scheduled_hook('masrbokra_check_balance');
});

// Enqueue Styles and Scripts
add_action('admin_enqueue_scripts', function ($hook) {
    if ($hook !== 'toplevel_page_masrbokra-sms-settings') {
        return;
    }
    wp_enqueue_style('masrbokra-tailwind', 'https://cdn.jsdelivr.net/npm/tailwindcss@2.2.19/dist/tailwind.min.css', [], '2.2.19');
    wp_enqueue_style('masrbokra-sms', plugin_dir_url(__FILE__) . 'assets/sms.css', [], '2.16');
    wp_enqueue_script('sweetalert2', 'https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.all.min.js', [], '11.12.4', true);
    wp_enqueue_script('masrbokra-sms-js', plugin_dir_url(__FILE__) . 'assets/sms.js', ['sweetalert2', 'jquery'], '2.16', true);
    wp_localize_script('masrbokra-sms-js', 'masrbokra_sms', [
        'ajax_url' => admin_url('admin-ajax.php'),
        'nonce' => wp_create_nonce('masrbokra_sms_nonce'),
        'lang' => get_option(SMS_OPTION_LANGUAGE, 'ar'),
        'is_rtl' => is_rtl() ? '1' : '0',
    ]);
});

// Admin Menu
add_action('admin_menu', function () {
    $is_rtl = is_rtl();
    $main_title = $is_rtl ? 'إعدادات الرسائل القصيرة' : 'SMS Settings';
    
    add_menu_page(
        $main_title,
        $main_title,
        'manage_options',
        'masrbokra-sms-settings',
        'masrbokra_sms_settings_page',
        'dashicons-email-alt',
        56
    );
});

// Main Settings Page with Tabs
function masrbokra_sms_settings_page() {
    $is_rtl = is_rtl();
    $page_title = $is_rtl ? 'إعدادات الرسائل القصيرة لـ WooCommerce' : 'WooCommerce SMS Settings';
    $active_tab = isset($_GET['tab']) ? sanitize_text_field($_GET['tab']) : 'dashboard';
    $tabs = [
        'dashboard' => $is_rtl ? 'لوحة التحكم' : 'Dashboard',
        'user_settings' => $is_rtl ? 'إعدادات المستخدم' : 'User Settings',
        'message_settings' => $is_rtl ? 'إعدادات الرسائل' : 'Message Settings',
        'marketing' => $is_rtl ? 'التسويق' : 'Marketing',
        'low_balance' => $is_rtl ? 'تنبيه انخفاض الرصيد' : 'Low Balance Alert',
    ];

    echo '<div class="wrap">';
    echo '<h1 class="text-2xl font-bold flex items-center"><span class="dashicons dashicons-email-alt mr-2"></span> ' . esc_html($page_title) . '</h1>';
    echo '<nav class="mt-6"><div class="border-b border-white"><ul class="flex -mb-px">';
    foreach ($tabs as $tab => $name) {
        $class = ($tab === $active_tab) ? 'border-blue-500 text-blue-600' : 'border-transparent text-gray-500 hover:text-gray-700 hover:border-gray-300';
        $url = admin_url('admin.php?page=masrbokra-sms-settings&tab=' . $tab);
        echo '<li><a href="' . esc_url($url) . '" class="inline-block py-4 px-6 text-sm font-medium ' . esc_attr($class) . ' border-b-2">' . esc_html($name) . '</a></li>';
    }
    echo '</ul></div></nav>';
    echo '<div class="card mt-6 p-6">';
    if ($active_tab === 'dashboard') {
        masrbokra_dashboard_tab();
    } elseif ($active_tab === 'user_settings') {
        masrbokra_user_settings_tab();
    } elseif ($active_tab === 'message_settings') {
        masrbokra_message_settings_tab();
    } elseif ($active_tab === 'marketing') {
        masrbokra_marketing_tab();
    } elseif ($active_tab === 'low_balance') {
        masrbokra_low_balance_tab();
    }
    echo '</div></div>';
}

// Dashboard Tab
function masrbokra_dashboard_tab() {
    $is_rtl = is_rtl();
    $labels = [
        'title' => $is_rtl ? 'لوحة التحكم بالإشعارات اليدوية' : 'Manual Notification Control Table',
        'date_time' => $is_rtl ? 'التاريخ / الوقت' : 'Date / Time',
        'customer' => $is_rtl ? 'العميل' : 'Customer',
        'type' => $is_rtl ? 'النوع' : 'Type',
        'otp' => $is_rtl ? 'رمز التحقق' : 'OTP',
        'status' => $is_rtl ? 'الحالة' : 'Status',
        'action' => $is_rtl ? 'الإجراء' : 'Action',
        'resend' => $is_rtl ? 'إعادة إرسال' : 'Resend',
        'clear_archive' => $is_rtl ? 'مسح الأرشيف' : 'Clear Archive',
        'no_messages' => $is_rtl ? 'لا توجد رسائل في الأرشيف.' : 'No messages in the archive.',
        'previous' => $is_rtl ? 'السابق' : 'Previous',
        'next' => $is_rtl ? 'التالي' : 'Next',
    ];

    $sent_messages = get_option(SMS_OPTION_SENT_MESSAGES, []);

    $messages_per_page = 10;
    $total_messages = count($sent_messages);
    $total_pages = ceil($total_messages / $messages_per_page);
    $current_page = isset($_GET['page_num']) ? max(1, absint($_GET['page_num'])) : 1;
    $current_page = min($current_page, $total_pages);
    $offset = ($current_page - 1) * $messages_per_page;
    $paged_messages = array_slice($sent_messages, $offset, $messages_per_page);

    echo '<h3 class="text-lg font-semibold mb-4">' . esc_html($labels['title']) . '</h3>';
    echo '<div class="mb-4">';
    echo '<button id="clear-archive-button" class="inline-flex items-center px-4 py-2 border border-transparent text-sm font-medium rounded-md shadow-sm text-white bg-red-600 hover:bg-red-700">' . esc_html($labels['clear_archive']) . '</button>';
    echo '</div>';

    if (empty($sent_messages)) {
        echo '<p>' . esc_html($labels['no_messages']) . '</p>';
    } else {
        echo '<table class="min-w-full divide-y divide-gray-200">';
        echo '<thead class="bg-gray-50">';
        echo '<tr>';
        echo '<th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">' . esc_html($labels['date_time']) . '</th>';
        echo '<th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">' . esc_html($labels['customer']) . '</th>';
        echo '<th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">' . esc_html($labels['type']) . '</th>';
        echo '<th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">' . esc_html($labels['otp']) . '</th>';
        echo '<th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">' . esc_html($labels['status']) . '</th>';
        echo '<th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">' . esc_html($labels['action']) . '</th>';
        echo '</tr>';
        echo '</thead>';
        echo '<tbody class="bg-white divide-y divide-gray-200">';
        foreach ($paged_messages as $index => $message) {
            $global_index = $offset + $index;
            echo '<tr>';
            echo '<td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">' . esc_html($message['date_time']) . '</td>';
            echo '<td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">' . esc_html($message['customer']) . '</td>';
            echo '<td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">' . esc_html($message['type']) . '</td>';
            echo '<td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">' . esc_html($message['otp']) . '</td>';
            echo '<td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">' . esc_html($message['status']) . '</td>';
            echo '<td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">';
            echo '<button class="inline-flex items-center px-4 py-2 border border-transparent text-sm font-medium rounded-md shadow-sm text-white bg-green-600 hover:bg-green-700 resend-button" data-index="' . esc_attr($global_index) . '" data-customer="' . esc_attr($message['customer']) . '" data-type="' . esc_attr($message['type']) . '" data-otp="' . esc_attr($message['otp']) . '">' . esc_html($labels['resend']) . '</button>';
            echo '</td>';
            echo '</tr>';
        }
        echo '</tbody>';
        echo '</table>';

        echo '<div class="mt-4 flex justify-between items-center">';
        echo '<div>';
        if ($current_page > 1) {
            $prev_page = $current_page - 1;
            $prev_url = admin_url('admin.php?page=masrbokra-sms-settings&tab=dashboard&page_num=' . $prev_page);
            echo '<a href="' . esc_url($prev_url) . '" class="inline-flex items-center px-4 py-2 border border-gray-300 text-sm font-medium rounded-md text-gray-700 bg-white hover:bg-gray-50">' . esc_html($labels['previous']) . '</a>';
        }
        echo '</div>';
        echo '<div class="flex space-x-2">';
        for ($i = 1; $i <= $total_pages; $i++) {
            $page_url = admin_url('admin.php?page=masrbokra-sms-settings&tab=dashboard&page_num=' . $i);
            $class = ($i === $current_page) ? 'bg-blue-600 text-white' : 'bg-white text-blue-600 hover:bg-blue-50';
            echo '<a href="' . esc_url($page_url) . '" class="px-4 py-2 border border-gray-300 text-sm font-medium rounded-md ' . esc_attr($class) . '">' . esc_html($i) . '</a>';
        }
        echo '</div>';
        echo '<div>';
        if ($current_page < $total_pages) {
            $next_page = $current_page + 1;
            $next_url = admin_url('admin.php?page=masrbokra-sms-settings&tab=dashboard&page_num=' . $next_page);
            echo '<a href="' . esc_url($next_url) . '" class="inline-flex items-center px-4 py-2 border border-gray-300 text-sm font-medium rounded-md text-gray-700 bg-white hover:bg-gray-50">' . esc_html($labels['next']) . '</a>';
        }
        echo '</div>';
        echo '</div>';
    }

    echo '<script>
        document.querySelectorAll(".resend-button").forEach(button => {
            button.addEventListener("click", function() {
                const index = this.dataset.index;
                const customer = this.dataset.customer;
                const type = this.dataset.type;
                const otp = this.dataset.otp;
                Swal.fire({
                    title: "' . esc_html($is_rtl ? 'تأكيد إعادة الإرسال' : 'Confirm Resend') . '",
                    text: "' . esc_html($is_rtl ? 'هل أنت متأكد من إعادة إرسال الرسالة إلى ' : 'Are you sure you want to resend the message to ') . '" + customer + "?",
                    icon: "warning",
                    showCancelButton: true,
                    confirmButtonText: "' . esc_html($is_rtl ? 'نعم، أعد الإرسال' : 'Yes, Resend') . '",
                    cancelButtonText: "' . esc_html($is_rtl ? 'إلغاء' : 'Cancel') . '",
                }).then((result) => {
                    if (result.isConfirmed) {
                        jQuery.ajax({
                            url: masrbokra_sms.ajax_url,
                            type: "POST",
                            data: {
                                action: "masrbokra_resend_sms",
                                nonce: masrbokra_sms.nonce,
                                index: index,
                                customer: customer,
                                type: type,
                                otp: otp
                            },
                            success: function(response) {
                                if (response.success) {
                                    Swal.fire(
                                        "' . esc_html($is_rtl ? 'تم إعادstrideالإرسال' : 'Resent') . '",
                                        "' . esc_html($is_rtl ? 'تم إعادة إرسال الرسالة بنجاح.' : 'The message has been resent successfully.') . '",
                                        "success"
                                    );
                                    location.reload();
                                } else {
                                    Swal.fire(
                                        "' . esc_html($is_rtl ? 'فشل' : 'Failed') . '",
                                        response.data.message,
                                        "error"
                                    );
                                }
                            },
                            error: function() {
                                Swal.fire(
                                    "' . esc_html($is_rtl ? 'خطأ' : 'Error') . '",
                                    "' . esc_html($is_rtl ? 'حدث خطأ أثناء إعادة الإرسال.' : 'An error occurred while resending.') . '",
                                    "error"
                                );
                            }
                        });
                    }
                });
            });
        });

        document.getElementById("clear-archive-button").addEventListener("click", function() {
            Swal.fire({
                title: "' . esc_html($is_rtl ? 'تأكيد مسح الأرشيف' : 'Confirm Clear Archive') . '",
                text: "' . esc_html($is_rtl ? 'هل أنت متأكد من مسح جميع الرسائل من الأرشيف؟' : 'Are you sure you want to clear all messages from the archive?') . '",
                icon: "warning",
                showCancelButton: true,
                confirmButtonText: "' . esc_html($is_rtl ? 'نعم، امسح' : 'Yes, Clear') . '",
                cancelButtonText: "' . esc_html($is_rtl ? 'إلغاء' : 'Cancel') . '",
                }).then((result) => {
                    if (result.isConfirmed) {
                        jQuery.ajax({
                            url: masrbokra_sms.ajax_url,
                            type: "POST",
                            data: {
                                action: "masrbokra_clear_archive",
                                nonce: masrbokra_sms.nonce
                            },
                            success: function(response) {
                                if (response.success) {
                                    Swal.fire(
                                        "' . esc_html($is_rtl ? 'تم المسح' : 'Cleared') . '",
                                        "' . esc_html($is_rtl ? 'تم مسح الأرشيف بنجاح.' : 'The archive has been cleared successfully.') . '",
                                        "success"
                                    );
                                    location.reload();
                                } else {
                                    Swal.fire(
                                        "' . esc_html($is_rtl ? 'فشل' : 'Failed') . '",
                                        response.data.message,
                                        "error"
                                    );
                                }
                            },
                            error: function() {
                                Swal.fire(
                                    "' . esc_html($is_rtl ? 'خطأ' : 'Error') . '",
                                    "' . esc_html($is_rtl ? 'حدث خطأ أثناء مسح الأرشيف.' : 'An error occurred while clearing the archive.') . '",
                                    "error"
                                );
                            }
                        });
                    }
                });
            });
    </script>';
}

// User Settings Tab
function masrbokra_user_settings_tab() {
    $is_rtl = is_rtl();
    $user = get_option(SMS_OPTION_USER);
    $pass = get_option(SMS_OPTION_PASS);
    $sender = get_option(SMS_OPTION_SENDER);
    $balance = masrbokra_get_balance($user, $pass);

    $labels = [
        'title' => $is_rtl ? 'إعدادات المستخدم' : 'User Settings',
        'username' => $is_rtl ? 'اسم مستخدم ShortBulkSMS' : 'ShortBulkSMS Username',
        'password' => $is_rtl ? 'كلمة مرور ShortBulkSMS' : 'ShortBulkSMS Password',
        'sender' => $is_rtl ? 'اسم المرسل' : 'Sender Name',
        'save' => $is_rtl ? 'حفظ التغييرات' : 'Save Changes',
        'balance' => $is_rtl ? 'الرصيد المتبقي: %s رسائل قصيرة' : 'Your Remaining Balance: %s SMS',
        'update_balance' => $is_rtl ? 'تحديث الرصيد' : 'Update Balance',
        'recharge' => $is_rtl ? 'إعادة الشحن' : 'Recharge',
        'get_credentials' => $is_rtl ? 'احصل على بياناتك من shotbulksms.com' : 'Get your credentials from shotbulksms.com',
    ];

    if (isset($_POST['masrbokra_user_settings_save'])) {
        update_option(SMS_OPTION_USER, sanitize_text_field($_POST['sms_user']));
        update_option(SMS_OPTION_PASS, sanitize_text_field($_POST['sms_pass']));
        update_option(SMS_OPTION_SENDER, sanitize_text_field($_POST['sms_sender']));
        echo '<div class="bg-green-100 border-l-4 border-green-500 text-green-700 p-4 mb-4" role="alert"><p><strong>' . esc_html__('Settings saved successfully.', 'masrbokra') . '</strong></p></div>';
        $balance = masrbokra_get_balance(sanitize_text_field($_POST['sms_user']), sanitize_text_field($_POST['sms_pass']));
    }

    echo '<h3 class="text-lg font-semibold mb-4">' . esc_html($labels['title']) . '</h3>';
    echo '<div class="mb-4"><a href="http://shotbulksms.com" target="_blank" class="inline-flex items-center px-4 py-2 border border-transparent text-sm font-medium rounded-md shadow-sm text-blue-600 hover:underline"><span class="dashicons dashicons-bell mr-2"></span>' . esc_html($labels['get_credentials']) . '</a></div>';
    echo '<form method="post">';
    echo '<table class="form-table">';
    echo '<tr><th class="text-sm font-medium text-gray-700">' . esc_html($labels['username']) . '</th><td><input type="text" name="sms_user" value="' . esc_attr($user) . '" class="mt-1 block w-full border border-gray-300 rounded-md shadow-sm p-2 focus:ring-blue-500 focus:border-blue-500" /></td></tr>';
    echo '<tr><th class="text-sm font-medium text-gray-700">' . esc_html($labels['password']) . '</th><td><input type="password" name="sms_pass" value="' . esc_attr($pass) . '" class="mt-1 block w-full border border-gray-300 rounded-md shadow-sm p-2 focus:ring-blue-500 focus:border-blue-500" /></td></tr>';
    echo '<tr><th class="text-sm font-medium text-gray-700">' . esc_html($labels['sender']) . '</th><td><input type="text" name="sms_sender" value="' . esc_attr($sender) . '" class="mt-1 block w-full border border-gray-300 rounded-md shadow-sm p-2 focus:ring-blue-500 focus:border-blue-500" /></td></tr>';
    echo '</table>';
    echo '<p class="mt-4"><input type="submit" name="masrbokra_user_settings_save" class="inline-flex items-center px-4 py-2 border border-transparent text-sm font-medium rounded-md shadow-sm text-white bg-green-600 hover:bg-green-700" value="' . esc_attr($labels['save']) . '" /></p>';
    echo '</form>';
    echo '<p class="mt-4 text-sm font-medium text-gray-700 balance-info">' . sprintf(esc_html($labels['balance']), is_wp_error($balance) ? 'N/A' : esc_html($balance)) . '</p>';
    echo '<div class="mt-2 flex space-x-2">';
    echo '<button id="update-balance-button" class="inline-flex items-center px-4 py-2 border border-transparent text-sm font-medium rounded-md shadow-sm text-white bg-green-600 hover:bg-green-700">' . esc_html($labels['update_balance']) . '</button>';
    echo '<a href="http://shotbulksms.com" target="_blank" class="inline-flex items-center px-4 py-2 border border-transparent text-sm font-medium rounded-md shadow-sm text-white bg-yellow-500 hover:bg-yellow-600">' . esc_html($labels['recharge']) . '</a>';
    echo '</div>';

    echo '<script>
        document.getElementById("update-balance-button").addEventListener("click", function() {
            jQuery.ajax({
                url: masrbokra_sms.ajax_url,
                type: "POST",
                data: {
                    action: "masrbokra_get_balance",
                    nonce: masrbokra_sms.nonce
                },
                success: function(response) {
                    if (response.success) {
                        Swal.fire(
                            "' . esc_html($is_rtl ? 'تم التحديث' : 'Updated') . '",
                            "' . esc_html($is_rtl ? 'تم تحديث الرصيد: ' : 'Balance updated: ') . '" + response.data.balance + " SMS",
                            "success"
                        );
                        document.querySelector(".balance-info").innerHTML = "' . sprintf(esc_html($labels['balance']), '" + response.data.balance + "') . '";
                    } else {
                        Swal.fire(
                            "' . esc_html($is_rtl ? 'فشل' : 'Failed') . '",
                            response.data.message,
                            "error"
                        );
                    }
                },
                error: function() {
                    Swal.fire(
                        "' . esc_html($is_rtl ? 'خطأ' : 'Error') . '",
                        "' . esc_html($is_rtl ? 'حدث خطأ أثناء تحديث الرصيد.' : 'An error occurred while updating the balance.') . '",
                        "error"
                    );
                }
            });
        });
    </script>';
}

// Message Settings Tab
function masrbokra_message_settings_tab() {
    if (isset($_POST['masrbokra_message_settings_save'])) {
        $notifications = [
            'otp_registration' => isset($_POST['otp_registration']) ? 'yes' : 'no',
            'otp_payment' => isset($_POST['otp_payment']) ? 'yes' : 'no',
            'order_confirmation' => isset($_POST['order_confirmation']) ? 'yes' : 'no',
            'order_processing' => isset($_POST['order_processing']) ? 'yes' : 'no',
            'order_shipping' => isset($_POST['order_shipping']) ? 'yes' : 'no',
            'order_delivery' => isset($_POST['order_delivery']) ? 'yes' : 'no',
            'order_cancellation' => isset($_POST['order_cancellation']) ? 'yes' : 'no',
            'abandoned_checkout' => isset($_POST['abandoned_checkout']) ? 'yes' : 'no',
            'forget_password' => isset($_POST['forget_password']) ? 'yes' : 'no',
        ];
        $messages = [
            'otp_registration' => [
                'ar' => sanitize_textarea_field($_POST['otp_registration_ar'] ?? ''),
                'en' => sanitize_textarea_field($_POST['otp_registration_en'] ?? ''),
            ],
            'otp_payment' => [
                'ar' => sanitize_textarea_field($_POST['otp_payment_ar'] ?? ''),
                'en' => sanitize_textarea_field($_POST['otp_payment_en'] ?? ''),
            ],
            'order_confirmation' => [
                'ar' => sanitize_textarea_field($_POST['order_confirmation_ar'] ?? ''),
                'en' => sanitize_textarea_field($_POST['order_confirmation_en'] ?? ''),
            ],
            'order_processing' => [
                'ar' => sanitize_textarea_field($_POST['order_processing_ar'] ?? ''),
                'en' => sanitize_textarea_field($_POST['order_processing_en'] ?? ''),
            ],
            'order_shipping' => [
                'ar' => sanitize_textarea_field($_POST['order_shipping_ar'] ?? ''),
                'en' => sanitize_textarea_field($_POST['order_shipping_en'] ?? ''),
            ],
            'order_delivery' => [
                'ar' => sanitize_textarea_field($_POST['order_delivery_ar'] ?? ''),
                'en' => sanitize_textarea_field($_POST['order_delivery_en'] ?? ''),
            ],
            'order_cancellation' => [
                'ar' => sanitize_textarea_field($_POST['order_cancellation_ar'] ?? ''),
                'en' => sanitize_textarea_field($_POST['order_cancellation_en'] ?? ''),
            ],
            'abandoned_checkout' => [
                'ar' => sanitize_textarea_field($_POST['abandoned_checkout_ar'] ?? ''),
                'en' => sanitize_textarea_field($_POST['abandoned_checkout_en'] ?? ''),
            ],
            'forget_password' => [
                'ar' => sanitize_textarea_field($_POST['forget_password_ar'] ?? ''),
                'en' => sanitize_textarea_field($_POST['forget_password_en'] ?? ''),
            ],
        ];
        update_option(SMS_OPTION_NOTIFICATIONS, $notifications);
        update_option(SMS_OPTION_MESSAGES, $messages);
        echo '<div class="bg-green-100 border-l-4 border-green-500 text-green-700 p-4 mb-4" role="alert"><p><strong>' . esc_html__('Settings and messages saved successfully.', 'masrbokra') . '</strong></p></div>';
    }

    $is_rtl = is_rtl();
    $notifications = get_option(SMS_OPTION_NOTIFICATIONS, []);
    $messages = get_option(SMS_OPTION_MESSAGES, [
        'otp_registration' => [
            'ar' => 'رمز التحقق الخاص بك: [otp]',
            'en' => 'Your verification code: [otp]',
        ],
        'otp_payment' => [
            'ar' => 'رمز التحقق للدفع لطلبك #[order_id]: [otp]',
            'en' => 'Payment verification code for order #[order_id]: [otp]',
        ],
        'order_confirmation' => [
            'ar' => 'تم استلام طلبك #[order_id]. شكراً لشرائك!',
            'en' => 'Your order #[order_id] has been received. Thank you for shopping!',
        ],
        'order_processing' => [
            'ar' => 'طلبك #[order_id] قيد المعالجة.',
            'en' => 'Your order #[order_id] is being processed.',
        ],
        'order_shipping' => [
            'ar' => 'طلبك #[order_id] تم شحنه.',
            'en' => 'Your order #[order_id] has been shipped.',
        ],
        'order_delivery' => [
            'ar' => 'طلبك #[order_id] تم تسليمه.',
            'en' => 'Your order #[order_id] has been delivered.',
        ],
        'order_cancellation' => [
            'ar' => 'تم إلغاء طلبك #[order_id].',
            'en' => 'Your order #[order_id] has been cancelled.',
        ],
        'abandoned_checkout' => [
            'ar' => 'لديك منتجات في سلة التسوق لم تكتمل بعد. أتمم الشراء الآن!',
            'en' => 'You have items in your cart that haven\'t been checked out. Complete your purchase now!',
        ],
        'forget_password' => [
            'ar' => 'رمز إعادة تعيين كلمة المرور: [otp]',
            'en' => 'Password reset code: [otp]',
        ],
    ]);

    $labels = [
        'title' => $is_rtl ? 'إعدادات الرسائل' : 'Message Settings',
        'otp_registration' => $is_rtl ? 'تفعيل رمز التحقق عند التسجيل' : 'Enable OTP on Registration',
        'otp_payment' => $is_rtl ? 'تفعيل رمز التحقق عند الدفع' : 'Enable OTP on Payment',
        'order_confirmation' => $is_rtl ? 'تفعيل إشعار تأكيد الطلب' : 'Enable Order Confirmation Notification',
        'order_processing' => $is_rtl ? 'تفعيل إشعار معالجة الطلب' : 'Enable Order Processing Notification',
        'order_shipping' => $is_rtl ? 'تفعيل إشعار شحن الطلب' : 'Enable Order Shipping Notification',
        'order_delivery' => $is_rtl ? 'تفعيل إشعار تسليم الطلب' : 'Enable Order Delivery Notification',
        'order_cancellation' => $is_rtl ? 'تفعيل إشعار إلغاء الطلب' : 'Enable Order Cancellation Notification',
        'abandoned_checkout' => $is_rtl ? 'تفعيل تذكير عربة التسوق المهجورة' : 'Enable Abandoned Checkout Reminder',
        'forget_password' => $is_rtl ? 'تفعيل نسيان كلمة المرور عبر الرسائل القصيرة' : 'Enable Forget Password by SMS',
        'message_ar' => $is_rtl ? 'نص الرسالة (العربية)' : 'Message Text (Arabic)',
        'message_en' => $is_rtl ? 'نص الرسالة (الإنجليزية)' : 'Message Text (English)',
        'save' => $is_rtl ? 'حفظ الإعدادات' : 'Save Settings',
    ];

    echo '<h3 class="text-lg font-semibold mb-4">' . esc_html($labels['title']) . '</h3>';
    echo '<form method="post">';
    echo '<table class="form-table">';
    
    foreach (['otp_registration', 'otp_payment', 'order_confirmation', 'order_processing', 'order_shipping', 'order_delivery', 'order_cancellation', 'abandoned_checkout', 'forget_password'] as $key) {
        $checked = isset($notifications[$key]) && $notifications[$key] === 'yes';
        
        echo '<tr>';
        echo '<th class="text-sm font-medium text-gray-700">' . esc_html($labels[$key]) . '</th>';
        echo '<td><label><input type="checkbox" name="' . esc_attr($key) . '" ' . checked($checked, true, false) . ' class="form-checkbox h-4 w-4 text-blue-600"></label></td>';
        echo '</tr>';

        echo '<tr class="message-ar-row">';
        echo '<th class="text-sm font-medium text-gray-700">' . esc_html($labels['message_ar']) . '</th>';
        echo '<td><textarea name="' . esc_attr($key) . '_ar" rows="3" class="mt-1 block w-full border border-gray-300 rounded-md shadow-sm p-2 focus:ring-blue-500 focus:border-blue-500">' . esc_textarea($messages[$key]['ar']) . '</textarea></td>';
        echo '</tr>';

        echo '<tr class="message-en-row">';
        echo '<th class="text-sm font-medium text-gray-700">' . esc_html($labels['message_en']) . '</th>';
        echo '<td><textarea name="' . esc_attr($key) . '_en" rows="3" class="mt-1 block w-full border border-gray-300 rounded-md shadow-sm p-2 focus:ring-blue-500 focus:border-blue-500">' . esc_textarea($messages[$key]['en']) . '</textarea></td>';
        echo '</tr>';
    }
    
    echo '</table>';
    echo '<p class="mt-4"><input type="submit" name="masrbokra_message_settings_save" class="inline-flex items-center px-4 py-2 border border-transparent text-sm font-medium rounded-md shadow-sm text-white bg-green-600 hover:bg-green-700" value="' . esc_attr($labels['save']) . '" /></p>';
    echo '</form>';
}

// Marketing Tab
function masrbokra_marketing_tab() {
    $is_rtl = is_rtl();
    $user = get_option(SMS_OPTION_USER);
    $pass = get_option(SMS_OPTION_PASS);
    $sender = get_option(SMS_OPTION_SENDER, 'Zara');
    $balance = masrbokra_get_balance($user, $pass);
    $balance = is_wp_error($balance) ? 0 : $balance;
    $user_count = count_users();
    $total_users = $user_count['total_users'];
    $verified_users = $total_users;
    $lang = get_option(SMS_OPTION_LANGUAGE, 'ar');

    $labels = [
        'title' => $is_rtl ? 'إرسال رسائل جماعية' : 'Send Bulk SMS',
        'verified_users' => $is_rtl ? 'عدد المستخدمين الموثقين: %d' : 'Number of Verified Users: %d',
        'sender' => $is_rtl ? 'اسم المرسل' : 'Sender Name',
        'message' => $is_rtl ? 'الرسالة' : 'Message',
        'placeholder' => $is_rtl ? 'اكتب رسالتك هنا...' : 'Type your message here...',
        'cost_per_user' => $is_rtl ? 'تكلفة الرسالة لكل مستخدم: %d رسائل قصيرة/مستخدم' : 'Message cost per user: %d SMS/User',
        'total_cost' => $is_rtl ? 'إجمالي الرسائل الجماعية: %d رسائل قصيرة' : 'Total Bulk SMS: %d SMS',
        'current_balance' => $is_rtl ? 'الرصيد الحالي: %d رسائل قصيرة' : 'Current Balance: %d SMS',
        'recharge' => $is_rtl ? 'إعادة الشحن' : 'Recharge',
        'send' => $is_rtl ? 'إرسال الرسائل القصيرة' : 'Send SMS',
    ];

    echo '<h3 class="text-lg font-semibold mb-4">' . esc_html($labels['title']) . '</h3>';
    echo '<p class="mb-2 text-sm font-medium text-gray-700">' . sprintf(esc_html($labels['verified_users']), $verified_users) . '</p>';
    echo '<form id="masrbokra-bulk-sms-form" method="post">';
    echo '<div class="mb-4"><label class="block text-sm font-medium text-gray-700">' . esc_html($labels['sender']) . '</label>';
    echo '<input type="text" name="sms_sender" value="' . esc_attr($sender) . '" class="mt-1 block w-full border border-gray-300 rounded-md shadow-sm p-2 focus:ring-blue-500 focus:border-blue-500" readonly /></div>';
    echo '<div class="mb-4"><label class="block text-sm font-medium text-gray-700">' . esc_html($labels['message']) . '</label>';
    echo '<textarea name="bulk_sms_message" rows="4" placeholder="' . esc_attr($labels['placeholder']) . '" class="mt-1 block w-full border border-gray-300 rounded-md shadow-sm p-2 focus:ring-blue-500 focus:border-blue-500" required oninput="updateCharCount(this)"></textarea>';
    echo '<p class="text-sm text-gray-500 mt-1 text-right"><span id="charCount">0</span> ' . esc_html__('characters', 'masrbokra') . '</p></div>';
    echo '<p class="mb-2 text-sm font-medium text-gray-700 cost-per-user">' . sprintf(esc_html($labels['cost_per_user']), 1) . '</p>';
    echo '<p class="mb-2 text-sm font-medium text-gray-700 total-cost">' . sprintf(esc_html($labels['total_cost']), $verified_users) . '</p>';
    echo '<p class="mb-2 text-sm font-medium text-gray-700">' . sprintf(esc_html($labels['current_balance']), $balance) . '</p>';
    echo '<p class="mb-4"><a href="http://shotbulksms.com" target="_blank" class="text-blue-600 hover:underline">' . esc_html($labels['recharge']) . '</a></p>';
    echo '<p><input type="submit" name="send_bulk_sms" class="inline-flex items-center px-4 py-2 border border-transparent text-sm font-medium rounded-md shadow-sm text-white bg-green-600 hover:bg-green-700" value="' . esc_attr($labels['send']) . '" /></p>';
    echo '<input type="hidden" name="verified_users" value="' . esc_attr($verified_users) . '">';
    echo '</form>';
}

// Low Balance Alert Tab
function masrbokra_low_balance_tab() {
    $is_rtl = is_rtl();
    $low_balance_alert = get_option(SMS_OPTION_LOW_BALANCE_ALERT, 'no');

    $labels = [
        'title' => $is_rtl ? 'تنبيه انخفاض الرصيد' : 'Low Balance Alert',
        'enable_low_balance_alert' => $is_rtl ? 'تفعيل تنبيه انخفاض الرصيد' : 'Enable Low Balance Alert',
        'save' => $is_rtl ? 'حفظ الإعدادات' : 'Save Settings',
    ];

    if (isset($_POST['masrbokra_low_balance_save'])) {
        update_option(SMS_OPTION_LOW_BALANCE_ALERT, isset($_POST['enable_low_balance_alert']) ? 'yes' : 'no');
        echo '<div class="bg-green-100 border-l-4 border-green-500 text-green-700 p-4 mb-4" role="alert"><p><strong>' . esc_html__('Low balance settings saved successfully.', 'masrbokra') . '</strong></p></div>';
    }

    echo '<h3 class="text-lg font-semibold mb-4">' . esc_html($labels['title']) . '</h3>';
    echo '<form method="post">';
    echo '<table class="form-table">';
    echo '<tr><th class="text-sm font-medium text-gray-700">' . esc_html($labels['enable_low_balance_alert']) . '</th><td><label><input type="checkbox" name="enable_low_balance_alert" ' . checked($low_balance_alert, 'yes', false) . ' class="form-checkbox h-4 w-4 text-blue-600"></label></td></tr>';
    echo '</table>';
    echo '<p class="mt-4"><input type="submit" name="masrbokra_low_balance_save" class="inline-flex items-center px-4 py-2 border border-transparent text-sm font-medium rounded-md shadow-sm text-white bg-green-600 hover:bg-green-700" value="' . esc_attr($labels['save']) . '" /></p>';
    echo '</form>';
}

// AJAX Handler for Clearing Archive
add_action('wp_ajax_masrbokra_clear_archive', function () {
    check_ajax_referer('masrbokra_sms_nonce', 'nonce');
    
    $result = update_option(SMS_OPTION_SENT_MESSAGES, []);
    
    if ($result !== false) {
        wp_send_json_success(['message' => __('Archive cleared successfully.', 'masrbokra')]);
    } else {
        wp_send_json_error(['message' => __('Failed to clear archive.', 'masrbokra')]);
    }
});

// AJAX Handler for Resending SMS
add_action('wp_ajax_masrbokra_resend_sms', function () {
    check_ajax_referer('masrbokra_sms_nonce', 'nonce');
    
    $index = isset($_POST['index']) ? absint($_POST['index']) : -1;
    $customer = isset($_POST['customer']) ? sanitize_text_field($_POST['customer']) : '';
    $type = isset($_POST['type']) ? sanitize_text_field($_POST['type']) : '';
    $otp = isset($_POST['otp']) ? sanitize_text_field($_POST['otp']) : '';

    if ($index === -1 || empty($customer) || empty($type)) {
        wp_send_json_error(['message' => __('Invalid request data.', 'masrbokra')]);
    }

    $notifications = get_option(SMS_OPTION_NOTIFICATIONS, []);
    $messages = get_option(SMS_OPTION_MESSAGES, []);
    $lang = get_option(SMS_OPTION_LANGUAGE, 'ar');

    $message_key = '';
    switch ($type) {
        case 'Registration OTP':
            $message_key = 'otp_registration';
            break;
        case 'Payment OTP':
            $message_key = 'otp_payment';
            break;
        case 'Order Confirmation':
            $message_key = 'order_confirmation';
            break;
        case 'Order Processing':
            $message_key = 'order_processing';
            break;
        case 'Order Shipping':
            $message_key = 'order_shipping';
            break;
        case 'Order Delivery':
            $message_key = 'order_delivery';
            break;
        case 'Order Cancellation':
            $message_key = 'order_cancellation';
            break;
        case 'Abandoned Checkout':
            $message_key = 'abandoned_checkout';
            break;
        case 'Forget Password':
            $message_key = 'forget_password';
            break;
        default:
            wp_send_json_error(['message' => __('Unsupported message type.', 'masrbokra')]);
    }

    if (empty($message_key) || !isset($messages[$message_key][$lang])) {
        wp_send_json_error(['message' => __('Message template not found.', 'masrbokra')]);
    }

    $message = $messages[$message_key][$lang];
    if ($otp !== 'None') {
        $message = str_replace('[otp]', $otp, $message);
    }
    if (strpos($message, '[order_id]') !== false) {
        $message = str_replace('[order_id]', 'N/A', $message);
    }

    $result = masrbokra_send_sms($customer, $message, $lang, $type, $otp);

    if ($result === 'Success') {
        $sent_messages = get_option(SMS_OPTION_SENT_MESSAGES, []);
        if (isset($sent_messages[$index])) {
            $sent_messages[$index]['date_time'] = current_time('d/m/Y H:i:s');
            $sent_messages[$index]['status'] = ($otp !== 'None') ? 'Active' : 'None';
            update_option(SMS_OPTION_SENT_MESSAGES, $sent_messages);
        }
        wp_send_json_success(['message' => __('SMS resent successfully.', 'masrbokra')]);
    } else {
        wp_send_json_error(['message' => __('Failed to resend SMS: ', 'masrbokra') . $result]);
    }
});

// AJAX Handler for Balance Check
add_action('wp_ajax_masrbokra_get_balance', function () {
    check_ajax_referer('masrbokra_sms_nonce', 'nonce');
    $user = get_option(SMS_OPTION_USER);
    $pass = get_option(SMS_OPTION_PASS);
    $balance = masrbokra_get_balance($user, $pass);
    if (is_wp_error($balance)) {
        wp_send_json_error(['message' => __('Failed to fetch balance: ', 'masrbokra') . $balance->get_error_message()]);
    } else {
        wp_send_json_success(['balance' => $balance]);
    }
});

// AJAX Handler for Batch SMS Sending
add_action('wp_ajax_masrbokra_send_batch_sms', function () {
    check_ajax_referer('masrbokra_sms_nonce', 'nonce');
    $batch_index = isset($_POST['batch_index']) ? absint($_POST['batch_index']) : 0;
    $message = isset($_POST['message']) ? sanitize_textarea_field($_POST['message']) : '';
    $lang = get_option(SMS_OPTION_LANGUAGE, 'ar');
    $transient_key = 'masrbokra_bulk_sms_' . md5($message . $lang . current_time('timestamp'));

    if (empty($message)) {
        wp_send_json_error(['message' => __('Message is empty.', 'masrbokra')]);
    }

    $phones = get_transient($transient_key);
    if ($phones === false) {
        global $wpdb;
        $phones = $wpdb->get_col(
            "SELECT DISTINCT um.meta_value 
             FROM {$wpdb->usermeta} um
             INNER JOIN {$wpdb->users} u ON um.user_id = u.ID
             WHERE um.meta_key = 'billing_phone' 
             AND um.meta_value != ''
             AND u.user_status = 0"
        );
        set_transient($transient_key, $phones, HOUR_IN_SECONDS);
    }

    if (empty($phones)) {
        delete_transient($transient_key);
        wp_send_json_error(['message' => __('No valid phone numbers found.', 'masrbokra')]);
    }

    $batch_size = 30;
    $batches = array_chunk($phones, $batch_size);
    if ($batch_index >= count($batches)) {
        delete_transient($transient_key);
        wp_send_json_success([
            'complete' => true,
            'message' => sprintf(__('Message sent to %d users.', 'masrbokra'), count($phones))
        ]);
    }

    $batch_phones = implode(',', $batches[$batch_index]);
    $result = masrbokra_send_sms($batch_phones, $message, $lang, 'Bulk SMS');

    if ($result === 'Success') {
        wp_send_json_success([
            'complete' => false,
            'next_batch' => $batch_index + 1,
            'progress' => min(100, round(($batch_index + 1) / count($batches) * 100))
        ]);
    } else {
        delete_transient($transient_key);
        wp_send_json_error(['message' => __('Failed to send batch: ', 'masrbokra') . $result]);
    }
});

// Check Balance and Notify Admin
add_action('masrbokra_check_balance', function () {
    $user = get_option(SMS_OPTION_USER);
    $pass = get_option(SMS_OPTION_PASS);
    $lang = get_option(SMS_OPTION_LANGUAGE, 'ar');
    $balance = masrbokra_get_balance($user, $pass);
    if (is_wp_error($balance)) {
        error_log("[SMS Balance Check] Failed: " . $balance->get_error_message());
        return;
    }

    $zero_notified = get_option(SMS_OPTION_ZERO_BALANCE_NOTIFIED, 'no');
    if (floatval($balance) <= 0 && $zero_notified === 'no') {
        $admin = get_user_by('id', 1);
        $phone = get_user_meta($admin->ID, 'billing_phone', true);
        if ($phone) {
            $message = $lang === 'ar' ? 'تحذير: رصيد الرسائل القصيرة وصل إلى صفر. يرجى تجديد الرصيد.' : 'Warning: SMS balance has reached zero. Please renew your balance.';
            $result = masrbokra_send_sms($phone, $message, $lang, 'Low Balance Alert');
            if ($result === 'Success') {
                update_option(SMS_OPTION_ZERO_BALANCE_NOTIFIED, 'yes');
            } else {
                error_log("[SMS Balance Alert] Failed to send: $result");
            }
        }
    } elseif (floatval($balance) > 0 && $zero_notified === 'yes') {
        update_option(SMS_OPTION_ZERO_BALANCE_NOTIFIED, 'no');
    }
});

// Fetch Balance via MasrBokra API
function masrbokra_get_balance($user, $password) {
    if (empty($user) || empty($password)) {
        error_log("[SMS Balance] Missing credentials: user=$user, password=" . (empty($password) ? 'empty' : 'set'));
        return new WP_Error('missing_credentials', 'Missing API credentials');
    }

    $url = 'http://sms.masrbokra.com/sendsms.php?' . http_build_query([
        'user' => trim($user),
        'password' => trim($password),
        'action' => 'get',
    ]);

    if (SMS_DEBUG) error_log("[SMS Balance] Requesting: $url");

    $response = wp_remote_get($url, [
        'timeout' => 15,
        'sslverify' => false,
    ]);

    if (is_wp_error($response)) {
        error_log("[SMS Balance] WP Error: " . $response->get_error_message());
        return $response;
    }

    $body = wp_remote_retrieve_body($response);
    if (SMS_DEBUG) error_log("[SMS Balance] Response: $body");

    $body = trim($body);
    if (strpos($body, 'Error') !== false) {
        error_log("[SMS Balance] API Error: $body");
        return new WP_Error('api_error', $body);
    }

    if (!is_numeric($body)) {
        error_log("[SMS Balance] Invalid response: $body");
        return new WP_Error('invalid_response', 'Invalid API response');
    }

    return $body;
}

// Send SMS Wrapper
function masrbokra_send_sms($number, $message, $lang = null, $type = 'Unknown', $otp = 'None') {
    $user = get_option(SMS_OPTION_USER);
    $pass = get_option(SMS_OPTION_PASS);
    $sender = get_option(SMS_OPTION_SENDER);
    $default_lang = get_option(SMS_OPTION_LANGUAGE, 'ar');
    $lang = $lang ?: $default_lang;

    // Validate phone number
    $number = preg_replace('/[^0-9,+]/', '', $number);
    if (!preg_match('/^\+?[1-9]\d{1,14}$/', $number)) {
        error_log("[SMS Send] Invalid phone number: $number, type: $type");
        return 'Invalid phone number';
    }

    // Validate message
    if (empty($message)) {
        error_log("[SMS Send] Empty message for number: $number, type: $type");
        return 'Empty message';
    }

    if (SMS_DEBUG) {
        error_log("[SMS Send] Preparing to send: number=$number, type=$type, message=$message, lang=$lang");
    }

    $result = send_sms_masrbokra($user, $pass, $sender, $number, $message, $lang);

    if ($result === 'Success') {
        $sent_messages = get_option(SMS_OPTION_SENT_MESSAGES, []);
        $status = ($otp !== 'None') ? 'Active' : 'None';
        $sent_messages[] = [
            'date_time' => current_time('d/m/Y H:i:s'),
            'customer' => $number,
            'type' => $type,
            'otp' => $otp,
            'status' => $status,
        ];
        update_option(SMS_OPTION_SENT_MESSAGES, $sent_messages);
        if (SMS_DEBUG) error_log("[SMS Send] Success: Message sent to $number, type: $type");
    } else {
        error_log("[SMS Send] Failed: number=$number, type=$type, result=$result");
    }

    return $result;
}

// Send via MasrBokra API with Message Splitting
function send_sms_masrbokra($user, $password, $sender, $number, $message, $lang = 'ar') {
    // Trim all inputs
    $user = trim($user);
    $password = trim($password);
    $sender = trim($sender);
    $number = trim($number);
    $message = trim($message);

    // Validate inputs
    if (empty($user) || empty($password) || empty($sender) || empty($number) || empty($message)) {
        $error = "[SMS Send] Missing or empty field: user=$user, sender=$sender, number=$number, message=$message";
        error_log($error);
        return $error;
    }

    // Clean message
    $message = preg_replace('/[^\p{L}\p{N}\s.,!?:;()]/u', '', $message);

    // Remove '+' from phone number to match example
    $number = ltrim($number, '+');

    $max_chars = ($lang === 'ar') ? 70 : 160;
    $split_max_chars = ($lang === 'ar') ? 67 : 153;

    // Calculate character count
    $char_count = 0;
    $chars = [];
    for ($i = 0; $i < mb_strlen($message, 'UTF-8'); $i++) {
        $char = mb_substr($message, $i, 1, 'UTF-8');
        $chars[] = $char;
        $char_count += ($char === "\n") ? 2 : 1;
    }

    if ($char_count <= $max_chars) {
        // Use GET request with 'message' parameter
        $query_data = [
            'user' => $user,
            'password' => $password,
            'sender' => $sender,
            'numbers' => $number,
            'message' => $message, // Changed from 'msg' to 'message'
            'lang' => $lang,
        ];

        $url = 'http://sms.masrbokra.com/sendsms.php?' . http_build_query($query_data);
        if (SMS_DEBUG) error_log("[SMS Send] GET Request: $url");

        $response = wp_remote_get($url, [
            'timeout' => 15,
            'sslverify' => false,
        ]);

        if (is_wp_error($response)) {
            $error = "[SMS Send] GET WP Error: " . $response->get_error_message();
            error_log($error);
            return $error;
        }

        $body = wp_remote_retrieve_body($response);
        $response_code = wp_remote_retrieve_response_code($response);
        $response_headers = wp_remote_retrieve_headers($response);
        if (SMS_DEBUG) {
            error_log("[SMS Send] Response Code: $response_code");
            error_log("[SMS Send] Response Headers: " . print_r($response_headers, true));
            error_log("[SMS Send] Response Body: $body");
        }

        $body = trim($body);
        if (strpos($body, 'Success') !== false) {
            return 'Success';
        } else {
            error_log("[SMS Send] API Error: $body");
            return $body;
        }
    } else {
        // Split message (unchanged)
        $messages = [];
        $current_message = '';
        $current_count = 0;

        foreach ($chars as $char) {
            $char_weight = ($char === "\n") ? 2 : 1;
            if ($current_count + $char_weight > $split_max_chars) {
                $messages[] = $current_message;
                $current_message = $char;
                $current_count = $char_weight;
            } else {
                $current_message .= $char;
                $current_count += $char_weight;
            }
        }

        if (!empty($current_message)) {
            $messages[] = $current_message;
        }

        $success = true;
        foreach ($messages as $index => $part) {
            $query_data = [
                'user' => $user,
                'password' => $password,
                'sender' => $sender,
                'numbers' => $number,
                'message' => $part, // Changed from 'msg' to 'message'
                'lang' => $lang,
            ];

            $url = 'http://sms.masrbokra.com/sendsms.php?' . http_build_query($query_data);
            if (SMS_DEBUG) error_log("[SMS Send Part $index] GET Request: $url");

            $response = wp_remote_get($url, [
                'timeout' => 15,
                'sslverify' => false,
            ]);

            if (is_wp_error($response)) {
                $error = "[SMS Send Part $index] GET WP Error: " . $response->get_error_message();
                error_log($error);
                $success = false;
                continue;
            }

            $body = wp_remote_retrieve_body($response);
            if (SMS_DEBUG) error_log("[SMS Send Part $index] Response: $body");

            $body = trim($body);
            if (strpos($body, 'Success') === false) {
                $success = false;
                error_log("[SMS Send Part $index] API Error: $body");
            }
        }

        return $success ? 'Success' : 'Failed to send all parts';
    }
}

// OTP for Registration
add_action('user_register', function ($user_id) {
    $notifications = get_option(SMS_OPTION_NOTIFICATIONS, []);
    if (!isset($notifications['otp_registration']) || $notifications['otp_registration'] !== 'yes') {
        error_log("[SMS OTP Registration] Disabled or not configured");
        return;
    }

    $user = get_user_by('id', $user_id);
    $phone = get_user_meta($user_id, 'billing_phone', true);
    if (!$phone) {
        error_log("[SMS OTP Registration] No phone number for user ID: $user_id");
        return;
    }

    $otp = wp_rand(100000, 999999);
    update_user_meta($user_id, 'registration_otp', $otp);
    update_user_meta($user_id, 'registration_otp_time', time());

    $messages = get_option(SMS_OPTION_MESSAGES, []);
    $lang = get_option(SMS_OPTION_LANGUAGE, 'ar');
    $message = $messages['otp_registration'][$lang] ?? 'رمز التحقق الخاص بك: [otp]';
    $message = str_replace('[otp]', $otp, $message);

    $result = masrbokra_send_sms($phone, $message, $lang, 'Registration OTP', $otp);
    if ($result !== 'Success') {
        error_log("[SMS OTP Registration] Failed for user ID: $user_id, phone: $phone, error: $result");
    }
});

// OTP for Payment
add_action('woocommerce_checkout_order_processed', function ($order_id) {
    $notifications = get_option(SMS_OPTION_NOTIFICATIONS, []);
    if (!isset($notifications['otp_payment']) || $notifications['otp_payment'] !== 'yes') {
        error_log("[SMS OTP Payment] Disabled or not configured for order ID: $order_id");
        return;
    }

    $order = wc_get_order($order_id);
    $phone = $order->get_billing_phone();
    if (!$phone) {
        error_log("[SMS OTP Payment] No phone number for order ID: $order_id");
        return;
    }

    $otp = wp_rand(100000, 999999);
    update_post_meta($order_id, 'payment_otp', $otp);
    update_post_meta($order_id, 'payment_otp_time', time());

    $messages = get_option(SMS_OPTION_MESSAGES, []);
    $lang = get_option(SMS_OPTION_LANGUAGE, 'ar');
    $message = $messages['otp_payment'][$lang] ?? 'رمز التحقق للدفع لطلبك #[order_id]: [otp]';
    $message = str_replace('[otp]', $otp, $message);
    $message = str_replace('[order_id]', $order_id, $message);

    $result = masrbokra_send_sms($phone, $message, $lang, 'Payment OTP', $otp);
    if ($result !== 'Success') {
        error_log("[SMS OTP Payment] Failed for order ID: $order_id, phone: $phone, error: $result");
    }
});

// Order Status Updates
add_action('woocommerce_order_status_changed', function ($order_id, $old_status, $new_status) {
    $notifications = get_option(SMS_OPTION_NOTIFICATIONS, []);
    $status_map = [
        'completed' => 'order_delivery',
        'processing' => 'order_processing',
        'shipped' => 'order_shipping',
        'cancelled' => 'order_cancellation',
        'pending' => 'order_confirmation',
    ];

    if (!isset($status_map[$new_status]) || !isset($notifications[$status_map[$new_status]]) || $notifications[$status_map[$new_status]] !== 'yes') {
        error_log("[SMS Order Status] Notification disabled or not configured for order ID: $order_id, status: $new_status");
        return;
    }

    $order = wc_get_order($order_id);
    $phone = $order->get_billing_phone();
    if (!$phone) {
        error_log("[SMS Order Status] No phone number for order ID: $order_id, status: $new_status");
        return;
    }

    $messages = get_option(SMS_OPTION_MESSAGES, []);
    $lang = get_option(SMS_OPTION_LANGUAGE, 'ar');
    $message_key = $status_map[$new_status];
    $message = $messages[$message_key][$lang] ?? '';
    if (empty($message)) {
        error_log("[SMS Order Status] No message template for order ID: $order_id, status: $new_status");
        return;
    }
    $message = str_replace('[order_id]', $order_id, $message);

    $type = ucwords(str_replace('_', ' ', $message_key));
    $result = masrbokra_send_sms($phone, $message, $lang, $type);
    if ($result !== 'Success') {
        error_log("[SMS Order Status] Failed for order ID: $order_id, phone: $phone, status: $new_status, error: $result");
    }
}, 10, 3);

// Abandoned Cart Reminder
add_action('masrbokra_check_abandoned_carts', function () {
    $notifications = get_option(SMS_OPTION_NOTIFICATIONS, []);
    if (!isset($notifications['abandoned_checkout']) || $notifications['abandoned_checkout'] !== 'yes') {
        error_log("[SMS Abandoned Cart] Disabled or not configured");
        return;
    }

    $timeout = 3600;
    $args = [
        'status' => 'checkout-draft',
        'date_created' => '<' . (time() - $timeout),
    ];

    $carts = wc_get_orders($args);
    foreach ($carts as $cart) {
        $phone = $cart->get_billing_phone();
        if (!$phone) {
            error_log("[SMS Abandoned Cart] No phone number for cart ID: " . $cart->get_id());
            continue;
        }

        $last_notified = get_post_meta($cart->get_id(), 'last_abandoned_notification', true);
        if ($last_notified && (time() - $last_notified) < DAY_IN_SECONDS) {
            continue;
        }

        $messages = get_option(SMS_OPTION_MESSAGES, []);
        $lang = get_option(SMS_OPTION_LANGUAGE, 'ar');
        $message = $messages['abandoned_checkout'][$lang] ?? 'لديك منتجات في سلة التسوق لم تكتمل بعد. أتمم الشراء الآن!';

        $result = masrbokra_send_sms($phone, $message, $lang, 'Abandoned Checkout');
        if ($result === 'Success') {
            update_post_meta($cart->get_id(), 'last_abandoned_notification', time());
        } else {
            error_log("[SMS Abandoned Cart] Failed for cart ID: " . $cart->get_id() . ", phone: $phone, error: $result");
        }
    }
});

// Password Reset via SMS
add_filter('retrieve_password_message', function ($message, $key, $user_login, $user_data) {
    $notifications = get_option(SMS_OPTION_NOTIFICATIONS, []);
    if (!isset($notifications['forget_password']) || $notifications['forget_password'] !== 'yes') {
        error_log("[SMS Password Reset] Disabled or not configured");
        return $message;
    }

    $phone = get_user_meta($user_data->ID, 'billing_phone', true);
    if (!$phone) {
        error_log("[SMS Password Reset] No phone number for user ID: " . $user_data->ID);
        return $message;
    }

    $otp = wp_rand(100000, 999999);
    update_user_meta($user_data->ID, 'password_reset_otp', $otp);
    update_user_meta($user_data->ID, 'password_reset_otp_time', time());
    update_user_meta($user_data->ID, 'password_reset_key', $key);

    $messages = get_option(SMS_OPTION_MESSAGES, []);
    $lang = get_option(SMS_OPTION_LANGUAGE, 'ar');
    $sms_message = $messages['forget_password'][$lang] ?? 'رمز إعادة تعيين كلمة المرور: [otp]';
    $sms_message = str_replace('[otp]', $otp, $sms_message);

    $result = masrbokra_send_sms($phone, $sms_message, $lang, 'Forget Password', $otp);
    if ($result !== 'Success') {
        error_log("[SMS Password Reset] Failed for user ID: " . $user_data->ID . ", phone: $phone, error: $result");
    }

    return $message;
}, 10, 4);

// Validate Password Reset OTP
add_action('validate_password_reset', function ($errors, $user) {
    $notifications = get_option(SMS_OPTION_NOTIFICATIONS, []);
    if (!isset($notifications['forget_password']) || $notifications['forget_password'] !== 'yes') {
        if (SMS_DEBUG) error_log("[SMS Password Reset Validation] Disabled or not configured for user ID: {$user->ID}");
        return;
    }

    $submitted_otp = isset($_POST['password_reset_otp']) ? sanitize_text_field($_POST['password_reset_otp']) : '';
    $stored_otp = get_user_meta($user->ID, 'password_reset_otp', true);
    $otp_time = get_user_meta($user->ID, 'password_reset_otp_time', true);

    if (empty($submitted_otp)) {
        $errors->add('missing_otp', __('Please enter the OTP sent to your phone.', 'masrbokra'));
        if (SMS_DEBUG) error_log("[SMS Password Reset Validation] Missing OTP for user ID: {$user->ID}");
        return;
    }

    if (!$stored_otp || !$otp_time) {
        $errors->add('invalid_otp', __('No valid OTP found. Please request a new one.', 'masrbokra'));
        if (SMS_DEBUG) error_log("[SMS Password Reset Validation] No stored OTP for user ID: {$user->ID}");
        return;
    }

    // Check if OTP is expired (e.g., 15 minutes)
    if ((time() - $otp_time) > 15 * MINUTE_IN_SECONDS) {
        $errors->add('expired_otp', __('The OTP has expired. Please request a new one.', 'masrbokra'));
        if (SMS_DEBUG) error_log("[SMS Password Reset Validation] Expired OTP for user ID: {$user->ID}");
        return;
    }

    if ($submitted_otp !== $stored_otp) {
        $errors->add('invalid_otp', __('Invalid OTP. Please try again.', 'masrbokra'));
        if (SMS_DEBUG) error_log("[SMS Password Reset Validation] Invalid OTP submitted for user ID: {$user->ID}, submitted: $submitted_otp, stored: $stored_otp");
        return;
    }

    // OTP is valid, allow password reset
    if (SMS_DEBUG) error_log("[SMS Password Reset Validation] OTP validated successfully for user ID: {$user->ID}");
    delete_user_meta($user->ID, 'password_reset_otp');
    delete_user_meta($user->ID, 'password_reset_otp_time');
}, 10, 2);

// Add OTP field to password reset form
add_action('resetpass_form', function ($user) {
    $notifications = get_option(SMS_OPTION_NOTIFICATIONS, []);
    if (!isset($notifications['forget_password']) || $notifications['forget_password'] !== 'yes') {
        return;
    }

    $is_rtl = is_rtl();
    $label = $is_rtl ? 'رمز التحقق (OTP)' : 'Verification Code (OTP)';
    $placeholder = $is_rtl ? 'أدخل رمز التحقق المرسل إلى هاتفك' : 'Enter the verification code sent to your phone';
    ?>
    <p class="user-pass-otp-wrap">
        <label for="password_reset_otp"><?php echo esc_html($label); ?></label>
        <input type="text" name="password_reset_otp" id="password_reset_otp" class="input" value="" size="20" placeholder="<?php echo esc_attr($placeholder); ?>" autocomplete="off" />
    </p>
    <?php
});

// Add custom styles for password reset form
add_action('login_enqueue_scripts', function () {
    ?>
    <style type="text/css">
        .user-pass-otp-wrap {
            margin-bottom: 1em;
        }
        .user-pass-otp-wrap label {
            display: block;
            margin-bottom: 0.5em;
        }
        .user-pass-otp-wrap input {
            width: 100%;
            padding: 0.5em;
            border: 1px solid #ccc;
            border-radius: 4px;
        }
    </style>
    <?php
});

// Ensure all SMS-related hooks are triggered correctly
add_action('woocommerce_checkout_order_processed', function ($order_id) {
    $notifications = get_option(SMS_OPTION_NOTIFICATIONS, []);
    if (!isset($notifications['order_confirmation']) || $notifications['order_confirmation'] !== 'yes') {
        if (SMS_DEBUG) error_log("[SMS Order Confirmation] Disabled or not configured for order ID: $order_id");
        return;
    }

    $order = wc_get_order($order_id);
    $phone = $order->get_billing_phone();
    if (!$phone) {
        if (SMS_DEBUG) error_log("[SMS Order Confirmation] No phone number for order ID: $order_id");
        return;
    }

    $messages = get_option(SMS_OPTION_MESSAGES, []);
    $lang = get_option(SMS_OPTION_LANGUAGE, 'ar');
    $message = $messages['order_confirmation'][$lang] ?? 'تم استلام طلبك #[order_id]. شكراً لشرائك!';
    $message = str_replace('[order_id]', $order_id, $message);

    $result = masrbokra_send_sms($phone, $message, $lang, 'Order Confirmation');
    if ($result !== 'Success') {
        error_log("[SMS Order Confirmation] Failed for order ID: $order_id, phone: $phone, error: $result");
    } else {
        if (SMS_DEBUG) error_log("[SMS Order Confirmation] Success for order ID: $order_id, phone: $phone");
    }
}, 10, 1);

add_action('woocommerce_order_status_changed', function ($order_id, $old_status, $new_status) {
    $notifications = get_option(SMS_OPTION_NOTIFICATIONS, []);
    $status_map = [
        'completed' => 'order_delivery',
        'processing' => 'order_processing',
        'shipped' => 'order_shipping',
        'cancelled' => 'order_cancellation',
        'pending' => 'order_confirmation',
    ];

    if (!isset($status_map[$new_status]) || !isset($notifications[$status_map[$new_status]]) || $notifications[$status_map[$new_status]] !== 'yes') {
        if (SMS_DEBUG) error_log("[SMS Order Status] Notification disabled or not configured for order ID: $order_id, status: $new_status");
        return;
    }

    $order = wc_get_order($order_id);
    $phone = $order->get_billing_phone();
    if (!$phone) {
        if (SMS_DEBUG) error_log("[SMS Order Status] No phone number for order ID: $order_id, status: $new_status");
        return;
    }

    $messages = get_option(SMS_OPTION_MESSAGES, []);
    $lang = get_option(SMS_OPTION_LANGUAGE, 'ar');
    $message_key = $status_map[$new_status];
    $message = $messages[$message_key][$lang] ?? '';
    if (empty($message)) {
        if (SMS_DEBUG) error_log("[SMS Order Status] No message template for order ID: $order_id, status: $new_status");
        return;
    }
    $message = str_replace('[order_id]', $order_id, $message);

    $type = ucwords(str_replace('_', ' ', $message_key));
    $result = masrbokra_send_sms($phone, $message, $lang, $type);
    if ($result !== 'Success') {
        error_log("[SMS Order Status] Failed for order ID: $order_id, phone: $phone, status: $new_status, error: $result");
    } else {
        if (SMS_DEBUG) error_log("[SMS Order Status] Success for order ID: $order_id, phone: $phone, status: $new_status");
    }
}, 10, 3);

// Ensure plugin text domain is loaded
add_action('plugins_loaded', function () {
    load_plugin_textdomain('masrbokra', false, dirname(plugin_basename(__FILE__)) . '/languages');
});

// Add settings link on plugins page
add_filter('plugin_action_links_' . plugin_basename(__FILE__), function ($links) {
    $settings_link = '<a href="' . admin_url('admin.php?page=masrbokra-sms-settings') . '">' . __('Settings', 'masrbokra') . '</a>';
    array_unshift($links, $settings_link);
    return $links;
});

// Ensure assets directory exists
function masrbokra_ensure_assets_directory() {
    $upload_dir = wp_upload_dir();
    $assets_dir = $upload_dir['basedir'] . '/masrbokra-assets';
    if (!file_exists($assets_dir)) {
        wp_mkdir_p($assets_dir);
    }
    return $assets_dir;
}

// Create assets on plugin activation
register_activation_hook(__FILE__, function () {
    masrbokra_ensure_assets_directory();
});

// Cleanup on plugin deactivation
register_deactivation_hook(__FILE__, function () {
    wp_clear_scheduled_hook('masrbokra_check_abandoned_carts');
    wp_clear_scheduled_hook('masrbokra_check_balance');
    delete_transient('masrbokra_bulk_sms_*');
});

add_action('admin_init', function() {
    if (current_user_can('manage_options') && isset($_GET['test_sms_api'])) {
        $user = get_option(SMS_OPTION_USER);
        $pass = get_option(SMS_OPTION_PASS);
        $sender = get_option(SMS_OPTION_SENDER);
        
        // Test balance check
        $balance = masrbokra_get_balance($user, $pass);
        error_log("[SMS TEST] Balance Check: " . print_r($balance, true));
        
        // Test sending SMS
        $test_number = '201061237563'; // Include country code
        $test_message = 'Test message from WooCommerce';
        $result = masrbokra_send_sms($test_number, $test_message, 'ar', 'Test');
        error_log("[SMS TEST] Send Result: " . $result);
        
        wp_die("Test completed. Check your error logs.");
    }
});
?>