(function($) {
    $(document).ready(function() {
        if (typeof Swal === 'undefined') {
            console.error('SweetAlert2 is not loaded.');
            return;
        }

        // Character and SMS count functions
        function calculateCharCount(message) {
            return Array.from(message).reduce((count, char) => {
                return count + (char === "\n" ? 2 : 1);
            }, 0);
        }

        function calculateSmsCount(message, lang) {
            const charCount = calculateCharCount(message);
            if (lang === "ar") {
                return charCount <= 70 ? 1 : Math.ceil(charCount / 67);
            } else {
                return charCount <= 160 ? 1 : Math.ceil(charCount / 153);
            }
        }

        window.updateCharCount = function(textarea) {
            const charCount = calculateCharCount(textarea.value);
            const smsCount = calculateSmsCount(textarea.value, masrbokra_sms.lang);
            $('#charCount').text(charCount);
            $('#smsCount').text(smsCount);

            const $form = $(textarea).closest('form');
            const verifiedUsers = parseInt($form.find('input[name="verified_users"]').val(), 10);
            const lang = masrbokra_sms.lang;
            const smsCountPerMessage = calculateSmsCount(textarea.value, lang);
            const totalSmsNeeded = verifiedUsers * smsCountPerMessage;

            $('.cost-per-user').text(
                masrbokra_sms.is_rtl === '1'
                    ? `تكلفة الرسالة لكل مستخدم: ${smsCountPerMessage} رسائل قصيرة/مستخدم`
                    : `Message cost per user: ${smsCountPerMessage} SMS/User`
            );
            $('.total-cost').text(
                masrbokra_sms.is_rtl === '1'
                    ? `إجمالي الرسائل الجماعية: ${totalSmsNeeded} رسائل قصيرة`
                    : `Total Bulk SMS: ${totalSmsNeeded} SMS`
            );
        };

        // Function to send batch SMS
        function sendBatchSms(message, batchIndex, totalUsers) {
            $.ajax({
                url: masrbokra_sms.ajax_url,
                method: 'POST',
                data: {
                    action: 'masrbokra_send_batch_sms',
                    nonce: masrbokra_sms.nonce,
                    message: message,
                    batch_index: batchIndex
                },
                success: function(response) {
                    if (response.success) {
                        if (response.data.complete) {
                            Swal.fire({
                                icon: 'success',
                                title: masrbokra_sms.is_rtl === '1' ? 'تم' : 'Success',
                                text: response.data.message,
                                confirmButtonText: masrbokra_sms.is_rtl === '1' ? 'موافق' : 'OK'
                            });
                        } else {
                            const progress = response.data.progress;
                            Swal.update({
                                text: (masrbokra_sms.is_rtl === '1'
                                    ? 'جاري الإرسال... ' + progress + '%'
                                    : 'Sending... ' + progress + '%')
                            });
                            setTimeout(function() {
                                sendBatchSms(message, response.data.next_batch, totalUsers);
                            }, 2000);
                        }
                    } else {
                        Swal.fire({
                            icon: 'error',
                            title: masrbokra_sms.is_rtl === '1' ? 'خطأ' : 'Error',
                            text: response.data.message,
                            confirmButtonText: masrbokra_sms.is_rtl === '1' ? 'موافق' : 'OK'
                        });
                    }
                },
                error: function(xhr, status, error) {
                    console.error('AJAX Error:', status, error);
                    Swal.fire({
                        icon: 'error',
                        title: masrbokra_sms.is_rtl === '1' ? 'خطأ' : 'Error',
                        text: masrbokra_sms.is_rtl === '1' ? 'حدث خطأ أثناء إرسال الرسائل.' : 'An error occurred while sending messages.',
                        confirmButtonText: masrbokra_sms.is_rtl === '1' ? 'موافق' : 'OK'
                    });
                }
            });
        }

        // Form submission handler for bulk SMS
        $('#masrbokra-bulk-sms-form').on('submit', function(e) {
            e.preventDefault();
            const $form = $(this);
            const message = $form.find('textarea[name="bulk_sms_message"]').val();
            const verifiedUsers = parseInt($form.find('input[name="verified_users"]').val(), 10);
            const lang = masrbokra_sms.lang;
            const smsCountPerMessage = calculateSmsCount(message, lang);
            const totalSmsNeeded = verifiedUsers * smsCountPerMessage;

            $.ajax({
                url: masrbokra_sms.ajax_url,
                method: 'POST',
                data: {
                    action: 'masrbokra_get_balance',
                    nonce: masrbokra_sms.nonce
                },
                success: function(response) {
                    if (response.success) {
                        const balance = parseInt(response.data.balance, 10);
                        if (balance < totalSmsNeeded) {
                            const errorMessage = masrbokra_sms.is_rtl === '1'
                                ? `رصيدك غير كافٍ. الرصيد الحالي: ${balance} رسالة، المطلوب: ${totalSmsNeeded} رسالة.`
                                : `Insufficient balance. Current balance: ${balance} SMS, Required: ${totalSmsNeeded} SMS.`;
                            Swal.fire({
                                icon: 'error',
                                title: masrbokra_sms.is_rtl === '1' ? 'خطأ' : 'Error',
                                text: errorMessage,
                                confirmButtonText: masrbokra_sms.is_rtl === '1' ? 'موافق' : 'OK',
                                showCancelButton: true,
                                cancelButtonText: masrbokra_sms.is_rtl === '1' ? 'شراء المزيد من الرصيد' : 'Buy More Balance',
                                cancelButtonColor: '#3085d6',
                                reverseButtons: masrbokra_sms.is_rtl === '1' ? true : false,
                            }).then((result) => {
                                if (result.isDismissed && result.dismiss === Swal.DismissReason.cancel) {
                                    window.location.href = 'https://sms.masrbokra.com/';
                                }
                            });
                        } else {
                            Swal.fire({
                                icon: 'info',
                                title: masrbokra_sms.is_rtl === '1' ? 'جاري الإرسال' : 'Sending',
                                text: masrbokra_sms.is_rtl === '1' ? 'جاري الإرسال... 0%' : 'Sending... 0%',
                                allowOutsideClick: false,
                                showConfirmButton: false
                            });
                            sendBatchSms(message, 0, verifiedUsers);
                        }
                    } else {
                        Swal.fire({
                            icon: 'error',
                            title: masrbokra_sms.is_rtl === '1' ? 'خطأ' : 'Error',
                            text: masrbokra_sms.is_rtl === '1' ? 'فشل في جلب الرصيد.' : 'Failed to fetch balance.',
                            confirmButtonText: masrbokra_sms.is_rtl === '1' ? 'موافق' : 'OK'
                        });
                    }
                },
                error: function(xhr, status, error) {
                    console.error('AJAX Error:', status, error);
                    Swal.fire({
                        icon: 'error',
                        title: masrbokra_sms.is_rtl === '1' ? 'خطأ' : 'Error',
                        text: masrbokra_sms.is_rtl === '1' ? 'حدث خطأ أثناء التحقق من الرصيد.' : 'An error occurred while checking balance.',
                        confirmButtonText: masrbokra_sms.is_rtl === '1' ? 'موافق' : 'OK'
                    });
                }
            });
        });

        // Toggle message fields visibility in Message Settings tab
        function toggleMessageFields() {
            $('input[type="checkbox"][name^="otp_"], input[type="checkbox"][name^="order_"], input[type="checkbox"][name="abandoned_checkout"], input[type="checkbox"][name="forget_password"]').each(function() {
                const $checkbox = $(this);
                const key = $checkbox.attr('name');
                const $arRow = $checkbox.closest('tr').next('tr.message-ar-row');
                const $enRow = $arRow.next('tr.message-en-row');

                if ($checkbox.is(':checked')) {
                    $arRow.show();
                    $enRow.show();
                } else {
                    $arRow.hide();
                    $enRow.hide();
                }
            });
        }

        // Initial toggle on page load
        toggleMessageFields();

        // Toggle on checkbox change
        $('input[type="checkbox"][name^="otp_"], input[type="checkbox"][name^="order_"], input[type="checkbox"][name="abandoned_checkout"], input[type="checkbox"][name="forget_password"]').on('change', function() {
            toggleMessageFields();
        });
    });
})(jQuery);