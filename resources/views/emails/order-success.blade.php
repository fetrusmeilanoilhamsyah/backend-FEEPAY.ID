<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Order Success</title>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, 'Helvetica Neue', Arial, sans-serif;
            line-height: 1.6;
            background-color: #f5f5f5;
            padding: 20px;
        }
        .email-container {
            max-width: 600px;
            margin: 0 auto;
            background-color: #ffffff;
            border-radius: 8px;
            overflow: hidden;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.1);
        }
        .email-header {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: #ffffff;
            padding: 40px 30px;
            text-align: center;
        }
        .email-header h1 {
            font-size: 28px;
            font-weight: 700;
            margin-bottom: 8px;
        }
        .email-header p {
            font-size: 16px;
            opacity: 0.95;
        }
        .email-body {
            padding: 40px 30px;
        }
        .success-badge {
            display: inline-block;
            background-color: #10b981;
            color: #ffffff;
            padding: 8px 16px;
            border-radius: 20px;
            font-size: 14px;
            font-weight: 600;
            margin-bottom: 24px;
        }
        .order-details {
            background-color: #f9fafb;
            border-radius: 8px;
            padding: 24px;
            margin-bottom: 24px;
        }
        .detail-row {
            display: flex;
            justify-content: space-between;
            padding: 12px 0;
            border-bottom: 1px solid #e5e7eb;
        }
        .detail-row:last-child {
            border-bottom: none;
        }
        .detail-label {
            font-size: 14px;
            color: #6b7280;
            font-weight: 500;
        }
        .detail-value {
            font-size: 14px;
            color: #111827;
            font-weight: 600;
            text-align: right;
        }
        .sn-box {
            background: linear-gradient(135deg, #fbbf24 0%, #f59e0b 100%);
            color: #ffffff;
            padding: 20px;
            border-radius: 8px;
            text-align: center;
            margin: 24px 0;
        }
        .sn-label {
            font-size: 12px;
            text-transform: uppercase;
            letter-spacing: 1px;
            opacity: 0.9;
            margin-bottom: 8px;
        }
        .sn-value {
            font-size: 24px;
            font-weight: 700;
            letter-spacing: 2px;
            font-family: 'Courier New', monospace;
        }
        .info-text {
            font-size: 14px;
            color: #6b7280;
            line-height: 1.8;
            margin-bottom: 16px;
        }
        .email-footer {
            background-color: #f9fafb;
            padding: 30px;
            text-align: center;
            border-top: 1px solid #e5e7eb;
        }
        .footer-text {
            font-size: 13px;
            color: #9ca3af;
            margin-bottom: 8px;
        }
        .brand-name {
            font-weight: 700;
            color: #667eea;
        }
        @media only screen and (max-width: 600px) {
            .email-body {
                padding: 24px 20px;
            }
            .email-header {
                padding: 32px 20px;
            }
            .email-header h1 {
                font-size: 24px;
            }
            .detail-row {
                flex-direction: column;
            }
            .detail-value {
                text-align: left;
                margin-top: 4px;
            }
        }
    </style>
</head>
<body>
    <div class="email-container">
        <!-- Header -->
        <div class="email-header">
            <h1>🎉 Transaction Successful!</h1>
            <p>Your order has been processed</p>
        </div>

        <!-- Body -->
        <div class="email-body">
            <span class="success-badge">✓ Completed</span>

            <p class="info-text">
                Hi there! Your transaction has been successfully processed. Below are your order details:
            </p>

            <!-- Order Details -->
            <div class="order-details">
                <div class="detail-row">
                    <span class="detail-label">Order ID</span>
                    <span class="detail-value">{{ $order->order_id }}</span>
                </div>
                <div class="detail-row">
                    <span class="detail-label">Product</span>
                    <span class="detail-value">{{ $product->name }}</span>
                </div>
                <div class="detail-row">
                    <span class="detail-label">Target Number</span>
                    <span class="detail-value">{{ $order->target_number }}</span>
                </div>
                <div class="detail-row">
                    <span class="detail-label">Total Price</span>
                    <span class="detail-value">Rp {{ number_format($order->total_price, 0, ',', '.') }}</span>
                </div>
                <div class="detail-row">
                    <span class="detail-label">Date</span>
                    <span class="detail-value">{{ $order->created_at->format('d M Y, H:i') }}</span>
                </div>
            </div>

            <!-- Serial Number -->
            @if($order->sn)
            <div class="sn-box">
                <div class="sn-label">Serial Number</div>
                <div class="sn-value">{{ $order->sn }}</div>
            </div>
            @endif

            <p class="info-text">
                Your product has been delivered to the destination number. If you experience any issues, please contact our support team.
            </p>

            <p class="info-text">
                Thank you for choosing <span class="brand-name">FEEPAY.ID</span>!
            </p>
        </div>

        <!-- Footer -->
        <div class="email-footer">
            <p class="footer-text">© {{ date('Y') }} FEEPAY.ID. All rights reserved.</p>
            <p class="footer-text">Digital Marketplace & USDT Exchange Platform</p>
        </div>
    </div>
</body>
</html>