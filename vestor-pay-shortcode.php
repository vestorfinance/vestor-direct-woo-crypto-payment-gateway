<?php
function vestor_pay_enqueue_scripts() {
    // Enqueue the QR Code library
    wp_enqueue_script(
        'qrcodejs', 
        'https://cdnjs.cloudflare.com/ajax/libs/qrcodejs/1.0.0/qrcode.min.js', 
        array(), 
        null, 
        true // Load in footer
    );
}
add_action('wp_enqueue_scripts', 'vestor_pay_enqueue_scripts');

function vestor_pay_shortcode() {
    // Ensure the order_id is present and sanitize it
    if (!isset($_GET['order_id'])) {
        return '<p>' . __('Invalid payment page. Please check your order details.', 'woocommerce') . '</p>';
    }

    // Sanitize and validate order_id
    $order_id = intval(sanitize_text_field(wp_unslash($_GET['order_id'])));
    $order = wc_get_order($order_id);

    // Check if order exists
    if (!$order) {
        return '<p>' . __('Invalid payment page. Please check your order details.', 'woocommerce') . '</p>';
    }

    $amount_zar = $order->get_total();
    $gateway = new WC_Gateway_Vestor_Pay();
    $conversion_rate = $gateway->conversion_rate;
    $amount_usd = $amount_zar / $conversion_rate; // Convert ZAR to USD

    $addresses = $gateway->addresses;
    $plugin_url = plugin_dir_url(__FILE__); // Get the plugin directory URL
    ob_start();
    ?>
<div id="vestor-payment-container" style="max-width:400px,background-color: #fff;border-radius:28px; border: 1px solid #ddd; padding:30px;">
    <div id="payment-selection">
        <h3 style="text-align: center;">USD <?php echo number_format($amount_usd, 2); ?></h3>
        <ul id="crypto-list">
            <?php foreach ($addresses as $currency => $address): ?>
                <?php if (!empty($address)): ?>
                    <li data-currency="<?php echo esc_attr($currency); ?>" data-address="<?php echo esc_attr($address); ?>" class="crypto-item">
                        <div class="crypto-pill">
                            <img src="<?php echo esc_url($plugin_url . 'icons/icon-' . strtolower($currency) . '.svg'); ?>" alt="<?php echo esc_attr($currency); ?>" class="crypto-icon" />
                            <span class="currency-name"><?php echo esc_html($currency); ?></span>
                            <span class="network-label"><?php echo ($currency == 'USDT_ETH') ? 'ETH' : (($currency == 'USDT_TRX') ? 'TRX' : (($currency == 'USDT_BSC') ? 'BSC' : '')); ?></span>
                        </div>
                        <span class="currency-code"><?php echo esc_html($currency); ?></span>
                    </li>
                <?php endif; ?>
            <?php endforeach; ?>
        </ul>
    </div>
    <div id="payment-details" class="hidden" style="text-align: center;">
        <h3>Send payment</h3>
        <div id="qr-code" style="margin-bottom: 15px; display: inline-block;"></div>
        <p style="word-wrap: break-word;"><img id="crypto-icon-large" src="" alt="" class="crypto-icon-large"> Only send <span id="crypto-name"></span> to this address:</p>
        <div id="crypto-address" style="cursor: pointer; font-weight: bold; display: inline-block; word-wrap: break-word; max-width: 100%;"></div>
        <span id="copy-text" style="font-weight: bold; cursor: pointer;">Copy</span>
        <p>Total amount: <span id="crypto-amount"><?php echo number_format($amount_usd, 2); ?></span> <span id="crypto-unit">USD</span><br> Send the equivalent to the wallet address.</p>
        <div id="countdown-timer">Time remaining: <span id="timer"></span></div>
        <button id="back-button" style="margin: 10px auto;">Back</button>
        <!-- Upload proof of payment -->
         <form id="payment-proof-form" enctype="multipart/form-data" style="margin-top: 15px;">
            <?php wp_nonce_field('vestor_pay_nonce_action', 'vestor_pay_nonce_field'); ?>
            <div class="form-group">
                <label for="payment-proof" class="form-label" style="font-family: 'Inter', sans-serif;">Upload Payment Proof</label>
                <input type="file" id="payment-proof" name="payment_proof" class="form-control" accept="image/*,application/pdf" required style="font-family: 'Inter', sans-serif; padding: 8px; border-radius: 4px; cursor: pointer;">
            </div>
            <button type="button" id="complete-payment" class="btn btn-primary" style="margin: 10px auto;">Complete payment</button>
        </form>
        <p id="confirmation-message" style="display:none; color: green;">Your payment will be verified by admin.</p>
    </div>
</div>
    <script>
        document.addEventListener('DOMContentLoaded', function() {
            const cryptoItems = document.querySelectorAll('.crypto-item');
            const paymentDetails = document.getElementById('payment-details');
            const paymentSelection = document.getElementById('payment-selection');
            const cryptoNameSpan = document.getElementById('crypto-name');
            const cryptoAddressSpan = document.getElementById('crypto-address');
            const cryptoAmountSpan = document.getElementById('crypto-amount');
            const cryptoUnitSpan = document.getElementById('crypto-unit');
            const cryptoIconLarge = document.getElementById('crypto-icon-large');
            const timerElement = document.getElementById('timer');
            const backButton = document.getElementById('back-button');
            const copyText = document.getElementById('copy-text');
            const completePaymentButton = document.getElementById('complete-payment');
            const confirmationMessage = document.getElementById('confirmation-message');
            let qrCodeElement = document.getElementById('qr-code');
            let qrCode; // Store the QR code instance

            let countdownInterval;

            cryptoItems.forEach(item => {
                item.addEventListener('click', function() {
                    const currency = this.getAttribute('data-currency');
                    const address = this.getAttribute('data-address');

                    // Hide the payment selection and show the payment details
                    paymentSelection.classList.add('hidden');
                    paymentDetails.classList.remove('hidden');

                    // Set payment details
                    cryptoNameSpan.textContent = currency;
                    cryptoAddressSpan.textContent = address;
                    cryptoUnitSpan.textContent = 'USD'; // Always set to USD
                    cryptoIconLarge.src = '<?php echo esc_url($plugin_url . 'icons/icon-'); ?>' + currency.toLowerCase() + '.svg';

                    // Clear previous QR code and generate a new one
                    qrCodeElement.innerHTML = ''; // Clear the QR code container
                    qrCode = new QRCode(qrCodeElement, {
                        text: address,
                        width: 128,
                        height: 128
                    });

                    // Set total amount to USD value
                    cryptoAmountSpan.textContent = '<?php echo number_format($amount_usd, 2); ?>';
                });
            });

            // Copy address functionality
            [cryptoAddressSpan, copyText].forEach(element => {
                element.addEventListener('click', function() {
                    navigator.clipboard.writeText(cryptoAddressSpan.textContent).then(function() {
                        alert('Address copied to clipboard!');
                    }, function() {
                        alert('Failed to copy address. Please try again.');
                    });
                });
            });

            backButton.addEventListener('click', function() {
                paymentDetails.classList.add('hidden');
                paymentSelection.classList.remove('hidden');
            });

            // Handle the payment proof submission
            completePaymentButton.addEventListener('click', function() {
                const formData = new FormData();
                formData.append('action', 'upload_payment_proof');
                formData.append('order_id', '<?php echo intval($order_id); ?>');
                formData.append('payment_proof', document.getElementById('payment-proof').files[0]);
                formData.append('vestor_pay_nonce_field', '<?php echo wp_create_nonce('vestor_pay_nonce_action'); ?>');
            
                console.log('Sending AJAX request for payment proof upload'); // Debugging log
            
                fetch('<?php echo admin_url('admin-ajax.php'); ?>', {
                    method: 'POST',
                    body: formData,
                })
                .then(response => response.json())
                .then(response => {
                    console.log('AJAX response:', response); // Debugging log
                    if (response.success) {
                        confirmationMessage.style.display = 'block'; // Show confirmation message
                    } else {
                        alert(response.data); // Show the error message
                    }
                })
                .catch(error => console.error('Error uploading payment proof:', error)); // Debugging log
            });



            // Countdown timer (example logic, adjust as needed)
            function startCountdown(duration) {
                let timer = duration, minutes, seconds;
                countdownInterval = setInterval(function () {
                    minutes = parseInt(timer / 60, 10);
                    seconds = parseInt(timer % 60, 10);

                    minutes = minutes < 10 ? "0" + minutes : minutes;
                    seconds = seconds < 10 ? "0" + seconds : seconds;

                    timerElement.textContent = minutes + ":" + seconds;

                    if (--timer < 0) {
                        clearInterval(countdownInterval);
                        // Handle countdown end logic here
                    }
                }, 1000);
            }

            startCountdown(1200); // 20 minutes countdown
        });
    </script>
    <?php
    return ob_get_clean();
}
add_shortcode('vestor-pay', 'vestor_pay_shortcode');

// Register AJAX actions for logged-in users
add_action('wp_ajax_upload_payment_proof', 'upload_payment_proof');

// Register AJAX actions for logged-out users
add_action('wp_ajax_nopriv_upload_payment_proof', 'upload_payment_proof');


function upload_payment_proof() {
    // Sanitize and validate order ID
    $order_id = isset($_POST['order_id']) ? intval(sanitize_text_field(wp_unslash($_POST['order_id']))) : 0;
    
    if ($order_id <= 0) {
        wp_send_json_error('Invalid order ID.');
        wp_die();
    }

    // Check if a file is uploaded
    if (isset($_FILES['payment_proof']['name']) && !empty($_FILES['payment_proof']['name'])) {
        $uploaded = wp_handle_upload($_FILES['payment_proof'], array('test_form' => false));

        // Check if the file was uploaded successfully
        if (isset($uploaded['url']) && !isset($uploaded['error'])) {
            $proof_url = esc_url($uploaded['url']);

            // Save proof URL and status in order meta
            update_post_meta($order_id, '_payment_proof_url', $proof_url);
            update_post_meta($order_id, '_payment_proof_status', 'pending');

            // Send an email to admin with the order details and proof
            $admin_email = get_option('admin_email');
            $subject = 'New Payment Proof for Order #' . $order_id;
            $message = '<p>A new payment proof has been uploaded for order #' . $order_id . '.</p>';
            $message .= '<p>You can view it here: <a href="' . esc_url($proof_url) . '">' . esc_url($proof_url) . '</a></p>';
            $headers = array('Content-Type: text/html; charset=UTF-8', 'From: Vestor Finance <' . esc_attr($admin_email) . '>');

            // Check if admin email is correctly fetched
            if (!empty($admin_email) && wp_mail($admin_email, $subject, $message, $headers)) {
                wp_send_json_success('Email sent successfully!');
            } else {
                wp_send_json_error('Failed to send email. Please check your email settings.');
            }
        } else {
            wp_send_json_error('Error uploading file: ' . $uploaded['error']);
        }
    } else {
        wp_send_json_error('No file uploaded.');
    }
    wp_die();
}






add_action('admin_menu', 'register_payment_proof_menu_page');
function register_payment_proof_menu_page() {
    add_menu_page(
        'Payment Proofs', // Page title
        'Payment Proofs', // Menu title
        'manage_options', // Capability required to access
        'payment-proofs', // Menu slug
        'payment_proof_menu_page', // Callback function to display the page content
        'dashicons-clipboard', // Icon for the menu
        6 // Position in the menu
    );
}

function payment_proof_menu_page() {
    global $wpdb;

    // Fetch all orders with a payment proof
    $orders_with_proof = $wpdb->get_results("
        SELECT post_id as order_id, meta_value as proof_url 
        FROM {$wpdb->postmeta} 
        WHERE meta_key = '_payment_proof_url'
    ");

    echo '<h1>Payment Proofs</h1>';
    echo '<table class="wp-list-table widefat fixed striped">';
    echo '<thead><tr><th>Order ID</th><th>Proof</th><th>Status</th><th>Action</th></tr></thead>';
    echo '<tbody>';

    foreach ($orders_with_proof as $proof) {
        $order_id = $proof->order_id;
        $proof_url = esc_url($proof->proof_url);
        $status = get_post_meta($order_id, '_payment_proof_status', true);

        echo '<tr>';
        echo '<td>' . $order_id . '</td>';
        echo '<td><a href="' . $proof_url . '" target="_blank">View Proof</a></td>';
        echo '<td>' . ucfirst($status) . '</td>';
        echo '<td>';
        if ($status == 'pending') {
            echo '<a href="#" onclick="processPaymentProof(' . $order_id . ', \'accept\')">Accept</a> | ';
            echo '<a href="#" onclick="processPaymentProof(' . $order_id . ', \'reject\')">Reject</a>';
        }
        echo '</td>';
        echo '</tr>';
    }

    echo '</tbody>';
    echo '</table>';
    ?>
    <script>
    function processPaymentProof(order_id, action) {
        jQuery.ajax({
            url: '<?php echo admin_url('admin-ajax.php'); ?>',
            method: 'POST',
            data: {
                action: 'process_payment_proof',
                order_id: order_id,
                process_action: action,
                _ajax_nonce: '<?php echo wp_create_nonce('process_payment_proof_nonce'); ?>'
            },
            success: function(response) {
                alert(response);
                location.reload();
            },
            error: function() {
                alert('An error occurred.');
            }
        });
    }
    </script>
    <?php
}


// Handle processing payment proofs
add_action('wp_ajax_process_payment_proof', 'process_payment_proof');
function process_payment_proof() {
    check_ajax_referer('process_payment_proof_nonce');

    $order_id = isset($_POST['order_id']) ? intval($_POST['order_id']) : 0;
    $action = isset($_POST['process_action']) ? sanitize_text_field(wp_unslash($_POST['process_action'])) : '';

    if ($action == 'accept') {
        $order = wc_get_order($order_id);
        $order->update_status('completed');
        update_post_meta($order_id, '_payment_proof_status', 'accepted');
        echo 'Payment accepted and order marked as completed.';
    } elseif ($action == 'reject') {
        $order = wc_get_order($order_id);
        $customer_email = $order->get_billing_email();
        update_post_meta($order_id, '_payment_proof_status', 'rejected');

        // Send rejection email to customer
        $subject = 'Your Payment Proof Has Been Rejected';
        $message = 'Your payment proof for order #' . $order_id . ' has been rejected. Please contact support for more information.';
        $headers = array('Content-Type: text/html; charset=UTF-8');

        wp_mail($customer_email, $subject, $message, $headers);

        echo 'Payment proof rejected and email sent to the customer.';
    } else {
        echo 'Invalid action.';
    }

    wp_die();
}
?>
