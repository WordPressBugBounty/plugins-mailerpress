<?php

namespace MailerPress\Core\Workflows\Handlers;

use DI\DependencyException;
use DI\NotFoundException;
use MailerPress\Core\EmailManager\EmailServiceManager;
use MailerPress\Core\Enums\Tables;
use MailerPress\Core\HtmlParser;
use MailerPress\Core\Kernel;
use MailerPress\Core\DynamicOrderRenderer;
use MailerPress\Core\Workflows\Models\Step;
use MailerPress\Core\Workflows\Models\AutomationJob;
use MailerPress\Core\Workflows\Results\StepResult;
use MailerPress\Models\Contacts as ContactsModel;

class SendEmailStepHandler implements StepHandlerInterface
{
    public function supports(string $key): bool
    {
        return $key === 'send_email' || $key === 'send_mail';
    }

    public function getDefinition(): array
    {
        return [
            'key' => 'send_email',
            'label' => __('Send Email', 'mailerpress'),
            'description' => __('Send a personalized email to the contact using a campaign template. You can customize the subject line and use all available workflow variables for dynamic content.', 'mailerpress'),
            'icon' => '<svg viewBox="-4 -4 24 20" xmlns="http://www.w3.org/2000/svg"><path fill-rule="evenodd" clip-rule="evenodd" d="M2.73578 1.5L8 6.01219L13.2642 1.5H2.73578ZM14.5 2.41638L8.48809 7.56944L8 7.98781L7.51191 7.56944L1.5 2.41638V10C1.5 10.2761 1.72386 10.5 2 10.5H14C14.2761 10.5 14.5 10.2761 14.5 10V2.41638ZM0 2C0 0.89543 0.89543 0 2 0H14C15.1046 0 16 0.895431 16 2V10C16 11.1046 15.1046 12 14 12H2C0.895431 12 0 11.1046 0 10V2Z"></path></svg>',
            'category' => 'communication',
            'type' => 'ACTION',
            'settings_schema' => [
                [
                    'key' => 'template_id',
                    'label' => 'Email Template',
                    'type' => 'select_dynamic',
                    'data_source' => 'campaigns',
                    'hidden' => true,
                    'required' => true,
                    'help' => __('Select an email template to send', 'mailerpress'),
                ],
                [
                    'key' => 'name',
                    'label' => 'Email Name *',
                    'type' => 'text',
                    'required' => true,
                    'help' => __('Give this email a name to identify it in conditions (e.g., "Welcome Email", "Order Confirmation")', 'mailerpress'),
                ],
                [
                    'key' => 'subject',
                    'label' => 'Subject *',
                    'type' => 'text',
                    'required' => true,
                    'help' => __('Override template subject (leave empty to use template default)', 'mailerpress'),
                ],
                // Example of a hidden field with default value
                // Hidden fields are automatically initialized with their default value and not shown in the UI
                // [
                //     'key' => 'email_type',
                //     'label' => 'Email Type',
                //     'type' => 'text',
                //     'hidden' => true,
                //     'default' => 'html',
                //     'required' => false,
                // ],
            ],
        ];
    }

    /**
     * @throws DependencyException
     * @throws NotFoundException
     * @throws \Exception
     */
    public function handle(Step $step, AutomationJob $job, array $context = []): StepResult
    {
        global $wpdb;

        $settings = $step->getSettings();
        $templateId = $settings['template_id'] ?? null;

        if (!$templateId) {
            return StepResult::failed('Template ID is required');
        }

        $templateId = (int) $templateId;

        // Récupérer le contact depuis le user_id
        // Note: For MailerPress contacts that are not WordPress users, user_id is actually contact_id
        $userId = $job->getUserId();
        if (!$userId) {
            return StepResult::failed('No user ID found');
        }

        $user = \get_userdata($userId);
        $contact = null;
        $contactId = null;
        $isContact = false;
        $userEmail = null;
        $userDisplayName = '';
        $userFirstName = '';
        $userLastName = '';

        // Check if userId is actually a contact_id (for MailerPress contacts without WordPress user)
        $contactsModel = new ContactsModel();
        $contactById = $contactsModel->get($userId);

        if ($contactById) {
            // userId is actually a contact_id - this is a MailerPress contact without WordPress user
            $contact = $contactById;
            $contactId = (int) $contact->contact_id;
            $isContact = true;
            $userEmail = $contact->email ?? '';
            $userDisplayName = trim(($contact->first_name ?? '') . ' ' . ($contact->last_name ?? '')) ?: $userEmail;
            $userFirstName = $contact->first_name ?? '';
            $userLastName = $contact->last_name ?? '';
        } elseif ($user && $user->user_email) {
            // userId is a valid WordPress user
            $userEmail = $user->user_email;
            $userDisplayName = $user->display_name ?? '';
            $userFirstName = $user->first_name ?? '';
            $userLastName = $user->last_name ?? '';

            // Try to find a MailerPress contact by email
            $contact = $contactsModel->getContactByEmail($userEmail);
            if ($contact) {
                $contactId = (int) $contact->contact_id;
                $isContact = true;
            }
        } else {
            // Check if this is a guest user (negative user_id from abandoned cart)
            // In this case, try to get email from context
            if ($userId < 0 && !empty($context['customer_email'])) {
                $userEmail = $context['customer_email'];
                $userDisplayName = trim(($context['customer_first_name'] ?? '') . ' ' . ($context['customer_last_name'] ?? '')) ?: $userEmail;
                $userFirstName = $context['customer_first_name'] ?? '';
                $userLastName = $context['customer_last_name'] ?? '';

                // Try to find a MailerPress contact by email
                $contact = $contactsModel->getContactByEmail($userEmail);
                if ($contact) {
                    $contactId = (int) $contact->contact_id;
                    $isContact = true;
                }
            } else {
                // No valid user or contact found
                return StepResult::failed('User or contact not found');
            }
        }

        // Récupérer la campagne depuis la base de données
        $campaignsTable = Tables::get(Tables::MAILERPRESS_CAMPAIGNS);
        $campaign = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT campaign_id, subject, content_html, config, campaign_type, status FROM {$campaignsTable} WHERE campaign_id = %d",
                $templateId
            )
        );

        if (!$campaign) {
            return StepResult::failed('Campaign template not found');
        }

        $campaignType = $campaign->campaign_type ?? 'newsletter';
        $campaignStatus = $campaign->status ?? 'draft';
        $isAutomationDraft = ($campaignType === 'automation' && $campaignStatus === 'draft');

        // Récupérer le sujet (priorité au paramètre, sinon celui de la campagne)
        $subject = !empty($settings['subject']) ? $settings['subject'] : ($campaign->subject ?? \__('Notification', 'mailerpress'));

        // Récupérer le HTML depuis l'option WordPress (stocker lors de la création de la campagne)
        $htmlContent = \get_option('mailerpress_batch_' . $templateId . '_html');

        // Si le HTML n'est pas disponible
        if (empty($htmlContent)) {
            // Pour les campagnes automation en draft, le HTML doit être généré lors de la sauvegarde
            if ($isAutomationDraft) {
                if (!empty($campaign->content_html)) {
                    return StepResult::failed(
                        'Campaign HTML content not available. For automation campaigns in draft status, please save the campaign first (the HTML will be generated automatically when you save).'
                    );
                } else {
                    return StepResult::failed(
                        'Campaign automation content is empty. Please add content to the campaign and save it first.'
                    );
                }
            }

            // Pour les autres types de campagnes, retourner une erreur
            if (!empty($campaign->content_html)) {
                return StepResult::failed(
                    'Campaign HTML content not available. Please save and send the campaign at least once to generate the HTML content.'
                );
            }

            return StepResult::failed('Campaign HTML content is empty');
        }

        // Préparer les variables pour le parsing
        $batchId = ''; // Pas de batch pour les workflows automatiques

        // Debug: Log template ID and contact info

        // For abandoned cart emails, verify cart is still active in tracking table
        // Check if this is an abandoned cart workflow by looking for cart_hash in context
        if (!empty($context['cart_hash']) && !empty($context['user_id'])) {
            $cartRepo = new \MailerPress\Core\Workflows\Repositories\CartTrackingRepository();
            // Check by user_id instead of cart_hash, since cart_hash changes when cart is updated
            if (!$cartRepo->hasActiveCart($context['user_id'])) {
                // Cancel the job since cart is not active
                $job->setStatus('CANCELLED');
                $jobRepo = new \MailerPress\Core\Workflows\Repositories\AutomationJobRepository();
                $jobRepo->update($job);
                return StepResult::failed('Cart is not active - abandoned cart email cancelled');
            }
        }

        if ($isContact) {
            // Cas 1 : Contact MailerPress existe - utiliser le tracking complet
            $unsubscribeToken = $contact->unsubscribe_token ?? '';
            $accessToken = $contact->access_token ?? '';
            $contactFirstName = $contact->first_name ?? $userFirstName;
            $contactLastName = $contact->last_name ?? $userLastName;
            $contactName = \trim($contactFirstName . ' ' . $contactLastName) ?: $userDisplayName;

            $variables = [
                'TRACK_CLICK' => \home_url('/'),
                'CONTACT_ID' => $contactId,
                'CAMPAIGN_ID' => $templateId,
                'JOB_ID' => $job->getId(), // For automation email click tracking
                'STEP_ID' => (string)$step->getStepId(), // For automation email click tracking
                'UNSUB_LINK' => \wp_unslash(
                    \sprintf(
                        '%s&data=%s&cid=%s&batchId=%s',
                        mailerpress_get_page('unsub_page'),
                        \esc_attr($unsubscribeToken),
                        \esc_attr($accessToken),
                        $batchId
                    )
                ),
                'MANAGE_SUB_LINK' => \wp_unslash(
                    \sprintf(
                        '%s&cid=%s',
                        mailerpress_get_page('manage_page'),
                        \esc_attr($accessToken)
                    )
                ),
                'CONTACT_NAME' => \esc_html($contactName),
                'TRACK_OPEN' => (function () use ($batchId, $contactId, $templateId, $job, $step) {
                    // Build TRACK_OPEN URL using token for automation emails (no batch)
                    if (empty($batchId) && !empty($contactId) && !empty($templateId) && $job->getId()) {
                        $token = \MailerPress\Core\HtmlParser::generateTrackOpenToken(
                            (int)$contactId,
                            (int)$templateId,
                            null, // no batchId for automation emails
                            (int)$job->getId(),
                            (string)$step->getStepId()
                        );
                        $url = \get_rest_url(null, \sprintf(
                            'mailerpress/v1/campaign/track-open?token=%s',
                            \urlencode($token)
                        ));
                        return $url;
                    }
                    // Build TRACK_OPEN URL using token for campaign emails (with batch)
                    elseif (!empty($batchId) && !empty($contactId) && !empty($templateId) && $job->getId()) {
                        $token = \MailerPress\Core\HtmlParser::generateTrackOpenToken(
                            (int)$contactId,
                            (int)$templateId,
                            (int)$batchId,
                            (int)$job->getId(),
                            (string)$step->getStepId()
                        );
                        $url = \get_rest_url(null, \sprintf(
                            'mailerpress/v1/campaign/track-open?token=%s',
                            \urlencode($token)
                        ));
                        return $url;
                    }
                    // Fallback: log why URL was not generated
                    else {
                        return '';
                    }
                })(),
                'contact_name' => \esc_html($contactName),
                'contact_email' => \esc_html($contact->email ?? $userEmail),
                'contact_first_name' => \esc_html($contactFirstName),
                'contact_last_name' => \esc_html($contactLastName),
            ];

            // Add custom fields to variables
            if ($contactId) {
                $customFieldsTable = Tables::get(Tables::MAILERPRESS_CONTACT_CUSTOM_FIELDS);
                $customFields = $wpdb->get_results($wpdb->prepare(
                    "SELECT field_key, field_value FROM {$customFieldsTable} WHERE contact_id = %d",
                    $contactId
                ));

                if ($customFields) {
                    foreach ($customFields as $customField) {
                        // Add custom field to variables using field_key as the key
                        $variables[$customField->field_key] = \esc_html($customField->field_value ?? '');
                    }
                }
            }
        } else {
            // Cas 2 : Pas de contact MailerPress - utiliser les données WordPress
            $contactName = \trim($userFirstName . ' ' . $userLastName) ?: $userDisplayName;

            // Pour les workflows, on utilise user_id comme contact_id pour les non-abonnés
            // Cela permet de tracker les emails même sans contact MailerPress
            // On utilise user_id directement comme contact_id pour les non-abonnés
            $contactId = $userId; // Utiliser user_id directement comme contact_id pour les utilisateurs non-abonnés
            $batchId = null; // Pas de batch pour les emails transactionnels

            $variables = [
                'TRACK_CLICK' => \home_url('/'),
                'CONTACT_ID' => $contactId,
                'CAMPAIGN_ID' => $templateId,
                'JOB_ID' => $job->getId(), // For automation email click tracking
                'STEP_ID' => (string)$step->getStepId(), // For automation email click tracking
                'UNSUB_LINK' => \home_url('/'),
                'MANAGE_SUB_LINK' => \home_url('/'),
                'CONTACT_NAME' => \esc_html($contactName),
                'TRACK_OPEN' => (function () use ($batchId, $contactId, $templateId, $job, $step) {
                    // Build TRACK_OPEN URL using token for transactional emails (no batch)
                    // Even without a MailerPress contact, we can track using user_id as contact_id
                    if (empty($batchId) && !empty($templateId) && $job->getId()) {
                        $token = \MailerPress\Core\HtmlParser::generateTrackOpenToken(
                            (int)$contactId, // user_id for non-subscribers
                            (int)$templateId,
                            null, // no batchId for automation emails
                            (int)$job->getId(),
                            (string)$step->getStepId()
                        );
                        $url = \sprintf(
                            '%s/wp-json/mailerpress/v1/campaign/track-open?token=%s',
                            \home_url(),
                            \urlencode($token)
                        );
                        return $url;
                    }
                    // Build TRACK_OPEN URL using token for campaign emails (with batch)
                    elseif (!empty($batchId) && !empty($templateId) && $job->getId()) {
                        $token = \MailerPress\Core\HtmlParser::generateTrackOpenToken(
                            (int)$contactId,
                            (int)$templateId,
                            (int)$batchId,
                            (int)$job->getId(),
                            (string)$step->getStepId()
                        );
                        $url = \sprintf(
                            '%s/wp-json/mailerpress/v1/campaign/track-open?token=%s',
                            \home_url(),
                            \urlencode($token)
                        );
                        return $url;
                    } else {
                        return '';
                    }
                })(),
                'contact_name' => \esc_html($contactName),
                'contact_email' => \esc_html($userEmail),
                'contact_first_name' => \esc_html($userFirstName),
                'contact_last_name' => \esc_html($userLastName),
            ];
        }

        if (isset($context['order_id'])) {
            $orderId = (int)($context['order_id'] ?? 0);

            // Helper function to log (use WooCommerce logger if available, otherwise MailerPress Logger)
            $logMessage = function ($message) use ($orderId) {
                if (function_exists('wc_get_logger')) {
                    $logger = \call_user_func('wc_get_logger');
                    $logger->info($message, ['source' => 'mailerpress-workflow']);
                } else {
                    \MailerPress\Services\Logger::info($message, ['order_id' => $orderId]);
                }
            };

            $logMessage("SendEmailStepHandler: Order ID found in context: {$orderId}");
            $logMessage("SendEmailStepHandler: Job user_id: {$userId}, Job ID: " . ($job->getId() ?? 'NULL'));
            $logMessage("SendEmailStepHandler: Context customer_email: " . ($context['customer_email'] ?? 'NULL'));
            $logMessage("SendEmailStepHandler: User email from job: {$userEmail}");

            // Verify order_id matches the job's user email to ensure we have the correct order
            // This prevents using an order from a different workflow/job
            $verifiedOrderId = $orderId;

            if ($orderId > 0 && function_exists('wc_get_order')) {
                $order = \call_user_func('wc_get_order', $orderId);
                if ($order) {
                    $orderEmail = $order->get_billing_email();
                    $orderCustomerId = $order->get_customer_id();

                    // Verify the order belongs to the job's user
                    // Check by email first (most reliable), then by customer_id
                    $emailMatches = !empty($userEmail) && strtolower($orderEmail) === strtolower($userEmail);
                    $customerIdMatches = ($orderCustomerId > 0 && $orderCustomerId == $userId) || ($orderCustomerId == 0 && $userId < 0);

                    if (!$emailMatches && !$customerIdMatches) {
                        $logMessage("SendEmailStepHandler: WARNING - Order #{$orderId} email ({$orderEmail}) does not match job user email ({$userEmail})");
                        $logMessage("SendEmailStepHandler: Attempting to find correct order for user email: {$userEmail}");

                        // Try to find the most recent order for this user
                        if (function_exists('wc_get_orders')) {
                            $orders = \call_user_func('wc_get_orders', [
                                'limit' => 1,
                                'orderby' => 'date',
                                'order' => 'DESC',
                                'customer' => $userEmail,
                                'status' => ['completed', 'processing'],
                            ]);

                            if (!empty($orders)) {
                                $correctOrder = $orders[0];
                                $verifiedOrderId = $correctOrder->get_id();
                                $logMessage("SendEmailStepHandler: Found correct order #{$verifiedOrderId} for user email {$userEmail}");
                                $orderId = $verifiedOrderId;
                                $order = $correctOrder;
                            } else {
                                $logMessage("SendEmailStepHandler: ERROR - Could not find any order for user email: {$userEmail}");
                            }
                        }
                    } else {
                        $logMessage("SendEmailStepHandler: Order #{$orderId} verified - email/customer_id matches job user");
                    }
                }
            }

            $variables['order_id'] = \esc_html($orderId);
            $variables['order_number'] = \esc_html($context['order_number'] ?? '');
            $variables['order_total'] = \esc_html($context['order_total'] ?? '');
            $variables['order_currency'] = \esc_html($context['order_currency'] ?? '');
            $variables['order_date'] = \esc_html($context['order_date'] ?? '');
            $variables['order_status'] = \esc_html($context['order_status'] ?? '');
            $variables['customer_first_name'] = \esc_html($context['customer_first_name'] ?? '');
            $variables['customer_last_name'] = \esc_html($context['customer_last_name'] ?? '');
            $variables['customer_email'] = \esc_html($context['customer_email'] ?? '');
            $logMessage("SendEmailStepHandler: Order variables added to email variables");

            // ALWAYS fetch order items directly from WooCommerce order to ensure we have the correct products
            // This ensures we're using the actual current state of the order, not cached context data
            $orderItems = null;
            $order = null; // Initialize order variable

            if ($orderId > 0 && function_exists('wc_get_order')) {
                // Use verified order if we found it, otherwise fetch again
                if (!isset($order) || !$order) {
                    $logMessage("SendEmailStepHandler: Fetching order items directly from WooCommerce order #{$orderId} to ensure accuracy");
                    $order = \call_user_func('wc_get_order', $orderId);
                }

                if ($order) {
                    // Verify this is the correct order
                    $orderNumber = $order->get_order_number();
                    $orderEmail = $order->get_billing_email();
                    $logMessage("SendEmailStepHandler: Verified order #{$orderId} - Order Number: {$orderNumber}, Customer Email: {$orderEmail}");

                    $orderItems = [];
                    foreach ($order->get_items() as $itemId => $item) {
                        $product = $item->get_product();
                        $itemProductId = $item->get_product_id();
                        $itemVariationId = $item->get_variation_id();
                        $itemName = $item->get_name();

                        // Get product thumbnail/image URL
                        $thumbnailUrl = '';
                        if ($product) {
                            $imageId = $product->get_image_id();
                            if ($imageId) {
                                $thumbnailUrl = wp_get_attachment_image_url($imageId, 'woocommerce_thumbnail');
                                if (!$thumbnailUrl) {
                                    // Fallback to full size if thumbnail not available
                                    $thumbnailUrl = wp_get_attachment_image_url($imageId, 'full');
                                }
                            }
                        }

                        $orderItems[] = [
                            'item_id' => $itemId,
                            'product_id' => $itemProductId,
                            'variation_id' => $itemVariationId,
                            'product_name' => $itemName,
                            'quantity' => $item->get_quantity(),
                            'subtotal' => $item->get_subtotal(),
                            'total' => $item->get_total(),
                            'sku' => $product ? $product->get_sku() : '',
                            'thumbnail_url' => $thumbnailUrl,
                        ];

                        $logMessage("SendEmailStepHandler: Order item - ID: {$itemProductId}, Variation: {$itemVariationId}, Name: {$itemName}, Thumbnail: " . ($thumbnailUrl ? 'YES' : 'NO'));
                    }
                    $logMessage("SendEmailStepHandler: Successfully fetched " . count($orderItems) . " items from WooCommerce order #{$orderId}");
                } else {
                    $logMessage("SendEmailStepHandler: ERROR - Could not fetch WooCommerce order #{$orderId}");
                    // Fallback to context if available
                    $orderItems = $context['order_items'] ?? null;
                    if ($orderItems) {
                        $logMessage("SendEmailStepHandler: Using order_items from context as fallback");
                    }
                }
            } else {
                $logMessage("SendEmailStepHandler: Invalid order_id ({$orderId}) or WooCommerce not available, using context order_items");
                $orderItems = $context['order_items'] ?? null;
            }

            // Store order_items in context for DynamicOrderRenderer (as array, not HTML)
            // DynamicOrderRenderer will generate the HTML table itself
            if (!empty($orderItems) && is_array($orderItems)) {
                // Add order_items to context for DynamicOrderRenderer
                $context['order_items'] = $orderItems;

                // Also generate HTML table rows for direct {{order_items}} merge tag replacement
                $currency = \esc_html($context['order_currency'] ?? 'EUR');
                $tableRows = '';

                // Header row
                $tableRows .= \sprintf(
                    '<tr style="background-color: #f5f5f5;">
                        <td style="padding: 12px; font-weight: bold; color: #333333; font-size: 14px; border-bottom: 2px solid #e0e0e0;">%s</td>
                        <td style="padding: 12px; font-weight: bold; color: #333333; font-size: 14px; border-bottom: 2px solid #e0e0e0; text-align: center;">%s</td>
                        <td style="padding: 12px; font-weight: bold; color: #333333; font-size: 14px; border-bottom: 2px solid #e0e0e0; text-align: right;">%s</td>
                        <td style="padding: 12px; font-weight: bold; color: #333333; font-size: 14px; border-bottom: 2px solid #e0e0e0; text-align: right;">%s</td>
                    </tr>',
                    \esc_html(\__('Product', 'mailerpress')),
                    \esc_html(\__('Quantity', 'mailerpress')),
                    \esc_html(\__('Price', 'mailerpress')),
                    \esc_html(\__('Total', 'mailerpress'))
                );

                // Data rows
                foreach ($orderItems as $index => $item) {
                    $productName = \esc_html($item['product_name'] ?? '');
                    $quantity = \esc_html($item['quantity'] ?? '0');
                    $itemTotal = \number_format((float)($item['total'] ?? 0), 2, '.', '');
                    $itemPrice = $quantity > 0 ? \number_format((float)($item['total'] ?? 0) / (float)$quantity, 2, '.', '') : '0.00';

                    $bgColor = ($index % 2 === 0) ? '#ffffff' : '#fafafa';

                    $tableRows .= \sprintf(
                        '<tr style="background-color: %s;">
                            <td style="padding: 12px; color: #333333; font-size: 14px; border-bottom: 1px solid #e0e0e0;">%s</td>
                            <td style="padding: 12px; color: #333333; font-size: 14px; border-bottom: 1px solid #e0e0e0; text-align: center;">%s</td>
                            <td style="padding: 12px; color: #333333; font-size: 14px; border-bottom: 1px solid #e0e0e0; text-align: right;">%s %s</td>
                            <td style="padding: 12px; color: #333333; font-size: 14px; border-bottom: 1px solid #e0e0e0; text-align: right; font-weight: bold;">%s %s</td>
                        </tr>',
                        $bgColor,
                        $productName,
                        $quantity,
                        $itemPrice,
                        $currency,
                        $itemTotal,
                        $currency
                    );
                }

                // Return the table rows HTML (will be used inside mj-table tag)
                $variables['order_items'] = $tableRows;

                // Generate product review links
                $productReviewLinks = [];
                $productReviewLinksHtml = '';
                $firstProductReviewLink = '';
                $firstProductName = '';
                $processedProductIds = []; // Track processed products to avoid duplicates

                // Helper function to log (use WooCommerce logger if available, otherwise MailerPress Logger)
                $logMessage = function ($message) use ($orderId) {
                    if (function_exists('wc_get_logger')) {
                        $logger = \call_user_func('wc_get_logger');
                        $logger->info($message, ['source' => 'mailerpress-workflow']);
                    } else {
                        \MailerPress\Services\Logger::info($message, ['order_id' => $orderId]);
                    }
                };

                $logMessage("SendEmailStepHandler: Generating product review links. Order items count: " . count($orderItems));

                foreach ($orderItems as $item) {
                    $productId = (int)($item['product_id'] ?? 0);
                    $variationId = (int)($item['variation_id'] ?? 0);

                    $logMessage("SendEmailStepHandler: Processing item - product_id: {$productId}, variation_id: {$variationId}, product_name: " . ($item['product_name'] ?? 'N/A'));

                    if ($productId <= 0) {
                        $logMessage("SendEmailStepHandler: Skipping item - invalid product_id: {$productId}");
                        continue;
                    }

                    // For variations, use the parent product ID for reviews (reviews are on parent products)
                    // But we still want to show the variation name
                    $reviewProductId = $productId;

                    // If this is a variation, get the parent product ID
                    if ($variationId > 0 && function_exists('wc_get_product')) {
                        $variationProduct = \call_user_func('wc_get_product', $variationId);
                        if ($variationProduct && $variationProduct->is_type('variation')) {
                            $reviewProductId = $variationProduct->get_parent_id();
                            // If parent ID is 0, fall back to product_id
                            if ($reviewProductId <= 0) {
                                $reviewProductId = $productId;
                            }
                        }
                    }

                    // Skip if we already processed this product (avoid duplicates for variations)
                    if (in_array($reviewProductId, $processedProductIds, true)) {
                        continue;
                    }

                    $processedProductIds[] = $reviewProductId;

                    $productName = \esc_html($item['product_name'] ?? '');

                    // Get the permalink for the product (parent product for variations)
                    $productUrl = \get_permalink($reviewProductId);

                    $logMessage("SendEmailStepHandler: Product URL for ID {$reviewProductId}: " . ($productUrl ?: 'NULL'));

                    if ($productUrl) {
                        // Add #reviews anchor to go directly to reviews section
                        $reviewUrl = $productUrl . '#reviews';
                        $reviewLink = \sprintf(
                            '<a href="%s" style="color: #0073aa; text-decoration: underline;">%s</a>',
                            \esc_url($reviewUrl),
                            $productName
                        );

                        $productReviewLinks[] = $reviewLink;

                        $logMessage("SendEmailStepHandler: Created review link for product '{$productName}' (ID: {$reviewProductId}, Original product_id: {$productId}): {$reviewUrl}");

                        if (empty($firstProductReviewLink)) {
                            $firstProductReviewLink = \esc_url($reviewUrl);
                            $firstProductName = $productName;
                            $logMessage("SendEmailStepHandler: ✓ Set first_product_review_link to product '{$productName}' (ID: {$reviewProductId}): {$firstProductReviewLink}");
                            $logMessage("SendEmailStepHandler: This is the FIRST product from order #{$orderId}");
                        } else {
                            $logMessage("SendEmailStepHandler: Skipping product '{$productName}' (ID: {$reviewProductId}) - first_product_review_link already set");
                        }
                    } else {
                        $logMessage("SendEmailStepHandler: Could not get permalink for product ID: {$reviewProductId}");
                    }
                }

                // Create HTML list of product review links
                if (!empty($productReviewLinks)) {
                    $productReviewLinksHtml = '<ul style="list-style: none; padding: 0; margin: 0;">';
                    foreach ($productReviewLinks as $link) {
                        $productReviewLinksHtml .= \sprintf(
                            '<li style="margin-bottom: 10px; padding: 0;">%s</li>',
                            $link
                        );
                    }
                    $productReviewLinksHtml .= '</ul>';
                }

                // Add merge tags for product review links
                $variables['product_review_links'] = $productReviewLinksHtml;
                $variables['first_product_review_link'] = $firstProductReviewLink;
                $variables['first_product_name'] = $firstProductName;
                $variables['product_review_links_count'] = (string)\count($productReviewLinks);

                // Debug: Log that we're setting the merge tag variable
                $logMessage("SendEmailStepHandler: Setting variable 'first_product_review_link' = " . ($firstProductReviewLink ?: 'EMPTY'));
            } else {
                // Even if no order items, set empty values to ensure merge tags are replaced (with empty string)
                $variables['product_review_links'] = '';
                $variables['first_product_review_link'] = '';
                $variables['first_product_name'] = '';
                $variables['product_review_links_count'] = '0';
            }

            if (isset($context['billing_address'])) {
                $billing = $context['billing_address'];
                $variables['billing_address'] = \esc_html(
                    \trim(
                        ($billing['first_name'] ?? '') . ' ' .
                            ($billing['last_name'] ?? '') . "\n" .
                            ($billing['address_1'] ?? '') . "\n" .
                            ($billing['address_2'] ?? '') . "\n" .
                            ($billing['city'] ?? '') . ', ' .
                            ($billing['state'] ?? '') . ' ' .
                            ($billing['postcode'] ?? '') . "\n" .
                            ($billing['country'] ?? '')
                    )
                );
            }

            if (isset($context['shipping_address'])) {
                $shipping = $context['shipping_address'];
                $variables['shipping_address'] = \esc_html(
                    \trim(
                        ($shipping['first_name'] ?? '') . ' ' .
                            ($shipping['last_name'] ?? '') . "\n" .
                            ($shipping['address_1'] ?? '') . "\n" .
                            ($shipping['address_2'] ?? '') . "\n" .
                            ($shipping['city'] ?? '') . ', ' .
                            ($shipping['state'] ?? '') . ' ' .
                            ($shipping['postcode'] ?? '') . "\n" .
                            ($shipping['country'] ?? '')
                    )
                );
            }
        }

        // Add email to variables if available in context
        if (!empty($context['email']) && !isset($variables['email'])) {
            $variables['email'] = \esc_html($context['email']);
        }
        if (!empty($userEmail) && !isset($variables['user_email'])) {
            $variables['user_email'] = \esc_html($userEmail);
        }

        // Add webhook_payload as a special variable to see all data
        if (isset($context['webhook_payload'])) {
            $variables['webhook_payload'] = \esc_html(json_encode($context['webhook_payload'], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        }
        if (isset($context['webhook_data'])) {
            $variables['webhook_data'] = \esc_html(json_encode($context['webhook_data'], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        }

        // Add all scalar values from context to variables (for custom data and other dynamic variables)
        // This ensures that custom data from custom triggers and other context values are available as merge tags
        foreach ($context as $key => $value) {
            // Skip non-scalar values and reserved keys
            if (!is_scalar($value)) {
                // For nested arrays/objects, try to flatten them for merge tags
                if (is_array($value) && !empty($value)) {
                    // Try to create dot-notation access for nested data
                    $this->flattenArrayForMergeTags($value, $variables, $key);
                }
                continue;
            }

            // Skip keys that are already set or are internal/reserved
            $reservedKeys = [
                'TRACK_CLICK',
                'CONTACT_ID',
                'CAMPAIGN_ID',
                'UNSUB_LINK',
                'MANAGE_SUB_LINK',
                'CONTACT_NAME',
                'TRACK_OPEN',
                'hook_name',
                'hook_arguments',
                'hook_arguments_count',
                'user_id',
                'contact_id',
                'custom_data',
                'parameter_1_custom_data',
                'parameter_2_custom_data',
                'arg_0',
                'arg_1',
                'arg_2',
                'arg_3',
                'arg_4',
                'arg_5',
                'arg_6',
                'arg_7',
                'arg_8',
                'arg_9'
            ];

            if (in_array(strtoupper($key), array_map('strtoupper', $reservedKeys))) {
                continue;
            }

            // Only add if not already set (to avoid overwriting specific variables)
            if (!isset($variables[$key])) {
                $variables[$key] = \esc_html((string) $value);
            }
        }

        if (isset($context['order_id']) && !empty($context['order_id'])) {
            try {
                $orderRenderer = new DynamicOrderRenderer($htmlContent, $context);
                $renderedHtml = $orderRenderer->render();

                // Only use rendered HTML if it's not empty and different from original
                if (!empty($renderedHtml) && $renderedHtml !== $htmlContent) {
                    $htmlContent = $renderedHtml;
                }
            } catch (\Throwable $e) {
                // Continue with original HTML content - don't break email sending
            } catch (\Exception $e) {
                // Continue with original HTML content - don't break email sending
            }
        }

        // Render abandoned cart blocks if cart data is available
        // First, try to get cart data from context, otherwise fetch from track_cart table
        $cartData = null;

        if (isset($context['cart_items']) && is_array($context['cart_items']) && count($context['cart_items']) > 0) {
            // Use cart data from context
            $cartData = [
                'cart_items' => $context['cart_items'],
                'cart_total' => $context['cart_total'] ?? '0',
                'cart_subtotal' => $context['cart_subtotal'] ?? '0',
                'cart_currency' => $context['cart_currency'] ?? 'EUR',
                'cart_item_count' => $context['cart_item_count'] ?? count($context['cart_items']),
            ];
        } elseif (!empty($context['user_id']) || !empty($context['cart_hash'])) {
            // Try to fetch cart data from track_cart table
            try {
                $cartRepo = new \MailerPress\Core\Workflows\Repositories\CartTrackingRepository();
                $activeCart = null;

                if (!empty($context['user_id'])) {
                    // Try to get active cart by user_id
                    $activeCart = $cartRepo->getActiveCartByUserId($context['user_id']);
                } elseif (!empty($context['cart_hash'])) {
                    // Fallback: try to get cart by cart_hash
                    $activeCart = $cartRepo->getCartByHash($context['cart_hash']);
                }

                if ($activeCart && !empty($activeCart['cart_data'])) {
                    $decodedCartData = json_decode($activeCart['cart_data'], true);
                    if ($decodedCartData && isset($decodedCartData['cart_items']) && is_array($decodedCartData['cart_items']) && count($decodedCartData['cart_items']) > 0) {
                        $cartData = [
                            'cart_items' => $decodedCartData['cart_items'],
                            'cart_total' => $decodedCartData['cart_total'] ?? '0',
                            'cart_subtotal' => $decodedCartData['cart_subtotal'] ?? '0',
                            'cart_currency' => $decodedCartData['cart_currency'] ?? 'EUR',
                            'cart_item_count' => $decodedCartData['cart_item_count'] ?? count($decodedCartData['cart_items']),
                        ];
                    }
                }
            } catch (\Throwable $e) {
            } catch (\Exception $e) {
            }
        }

        // Render abandoned cart items if we have cart data
        if ($cartData && !empty($cartData['cart_items']) && is_array($cartData['cart_items']) && count($cartData['cart_items']) > 0) {
            try {
                // Render abandoned cart items table
                $htmlContent = $this->renderAbandonedCartItems($htmlContent, $cartData);
            } catch (\Throwable $e) {
                // Continue with original HTML content - don't break email sending
            } catch (\Exception $e) {
                // Continue with original HTML content - don't break email sending
            }
        }

        // Check if HTML contains merge tag before parsing
        // First, decode any HTML entities that might have been encoded
        $htmlContent = html_entity_decode($htmlContent, ENT_QUOTES | ENT_HTML5, 'UTF-8');

        // Also check for URL-encoded merge tags and decode them
        if (strpos($htmlContent, '%7B%7B') !== false || strpos($htmlContent, '%7D%7D') !== false) {
            $htmlContent = str_replace('%7B%7B', '{{', $htmlContent);
            $htmlContent = str_replace('%7D%7D', '}}', $htmlContent);
        }

        // Parser le HTML avec les variables
        // Le HtmlParser gère automatiquement les cas où CONTACT_ID = 0 (pas de tracking)
        // IMPORTANT: Use the potentially decoded $htmlContent (merge tags may have been decoded above)
        $htmlParser = Kernel::getContainer()->get(HtmlParser::class);
        $parsedHtml = $htmlParser->init($htmlContent, $variables)->replaceVariables();

        // Récupérer le service d'email actif
        $mailer = Kernel::getContainer()->get(EmailServiceManager::class)->getActiveService();
        $config = $mailer->getConfig();

        // Récupérer les paramètres d'expéditeur par défaut
        if (
            empty($config['conf']['default_email'])
            || empty($config['conf']['default_name'])
        ) {
            $globalSender = \get_option('mailerpress_default_settings');

            if ($globalSender) {
                if (is_string($globalSender)) {
                    $globalSender = json_decode($globalSender, true);
                }

                if (is_array($globalSender)) {
                    $config['conf']['default_email'] = $globalSender['fromAddress'] ?? '';
                    $config['conf']['default_name'] = $globalSender['fromName'] ?? '';
                }
            }
        }

        // Envoyer l'email via le service MailerPress
        try {
            $sent = $mailer->sendEmail([
                'to' => $userEmail,
                'html' => true,
                'body' => $parsedHtml,
                'subject' => $subject,
                'sender_name' => $config['conf']['default_name'] ?? '',
                'sender_to' => $config['conf']['default_email'] ?? '',
                'apiKey' => $config['conf']['api_key'] ?? '',
            ]);

            if (!$sent) {
                return StepResult::failed('Failed to send email via MailerPress service');
            }

            // Store email send information for future condition evaluation
            // This ensures we can verify that the email was opened AFTER it was sent in THIS workflow
            $emailSentAt = current_time('mysql');

            // Use user_id as contact_id if user is not a MailerPress contact
            // This allows tracking even for non-subscribers using user_id
            $finalContactId = $isContact ? $contactId : $userId;

            return StepResult::success($step->getNextStepId(), [
                'email_sent' => true,
                'recipient' => $userEmail,
                'campaign_id' => $templateId,
                'contact_id' => $finalContactId,
                'is_contact' => $isContact,
                'email_sent_at' => $emailSentAt,
                'job_id' => $job->getId(),
                'step_id' => $step->getStepId(),
            ]);
        } catch (\Exception $e) {
            return StepResult::failed('Error sending email: ' . $e->getMessage());
        }
    }

    /**
     * Render abandoned cart items table in email HTML
     * 
     * @param string $htmlContent
     * @param array $cartData
     * @return string
     */
    protected function renderAbandonedCartItems(string $htmlContent, array $cartData): string
    {
        if (empty($cartData['cart_items']) || !is_array($cartData['cart_items'])) {
            return $htmlContent;
        }

        // Find abandoned cart items blocks
        preg_match_all(
            '/(<!-- START abandoned cart items table -->)(.*?)(<!-- END abandoned cart items table -->)/is',
            $htmlContent,
            $blocks,
            PREG_SET_ORDER
        );

        if (count($blocks) === 0) {
            return $htmlContent;
        }

        // Generate table rows from cart items
        $tableRows = $this->generateCartItemsTableRows($cartData);

        foreach ($blocks as $blockIndex => $block) {
            $fullMatch = $block[0];
            $startComment = $block[1];
            $blockContent = $block[2];
            $endComment = $block[3];

            // Try to extract mj-table attributes from the original block
            $mjTableAttributes = '';
            if (preg_match('/<mj-table([^>]*)>/i', $blockContent, $attrMatches)) {
                $mjTableAttributes = $attrMatches[1];
            }

            // Build new mj-table with the generated rows
            $newMjTable = '<mj-table' . $mjTableAttributes . '>' . $tableRows . '</mj-table>';

            // Replace the entire block content with the new mj-table
            $newBlock = $startComment . $newMjTable . $endComment;

            // Replace in HTML
            $htmlContent = str_replace($fullMatch, $newBlock, $htmlContent);
        }

        return $htmlContent;
    }

    /**
     * Generate HTML table rows for cart items
     * 
     * @param array $cartData
     * @return string
     */
    protected function generateCartItemsTableRows(array $cartData): string
    {
        $items = $cartData['cart_items'];
        $currency = $cartData['cart_currency'] ?? 'EUR';

        $rows = '';

        // Header row
        $rows .= '<tr style="background-color: #f5f5f5;">';
        $rows .= '<td style="padding: 12px; font-weight: bold; color: #333333; font-size: 14px; border-bottom: 2px solid #e0e0e0;">' . \__('Product', 'mailerpress') . '</td>';
        $rows .= '<td style="padding: 12px; font-weight: bold; color: #333333; font-size: 14px; border-bottom: 2px solid #e0e0e0; text-align: center;">' . \__('Quantity', 'mailerpress') . '</td>';
        $rows .= '<td style="padding: 12px; font-weight: bold; color: #333333; font-size: 14px; border-bottom: 2px solid #e0e0e0; text-align: right;">' . \__('Price', 'mailerpress') . '</td>';
        $rows .= '<td style="padding: 12px; font-weight: bold; color: #333333; font-size: 14px; border-bottom: 2px solid #e0e0e0; text-align: right;">' . \__('Total', 'mailerpress') . '</td>';
        $rows .= '</tr>';

        // Data rows
        foreach ($items as $index => $item) {
            $isEven = $index % 2 === 0;
            $bgColor = $isEven ? '#ffffff' : '#fafafa';
            $itemTotal = isset($item['line_total']) ? number_format((float)$item['line_total'], 2, '.', '') : '0.00';
            $quantity = $item['quantity'] ?? 0;
            $itemPrice = $quantity > 0 ? number_format((float)$item['line_total'] / (float)$quantity, 2, '.', '') : '0.00';
            $productName = $item['product_name'] ?? '';

            $rows .= '<tr style="background-color: ' . $bgColor . ';">';
            $rows .= '<td style="padding: 12px; color: #333333; font-size: 14px; border-bottom: 1px solid #e0e0e0;">' . \esc_html($productName) . '</td>';
            $rows .= '<td style="padding: 12px; color: #333333; font-size: 14px; border-bottom: 1px solid #e0e0e0; text-align: center;">' . \esc_html($quantity) . '</td>';
            $rows .= '<td style="padding: 12px; color: #333333; font-size: 14px; border-bottom: 1px solid #e0e0e0; text-align: right;">' . \esc_html($itemPrice . ' ' . $currency) . '</td>';
            $rows .= '<td style="padding: 12px; color: #333333; font-size: 14px; border-bottom: 1px solid #e0e0e0; text-align: right; font-weight: bold;">' . \esc_html($itemTotal . ' ' . $currency) . '</td>';
            $rows .= '</tr>';
        }

        return $rows;
    }

    /**
     * Aplatit un tableau pour créer des merge tags avec notation point
     * 
     * @param array $data Données à aplatir
     * @param array &$variables Variables de merge tags à enrichir
     * @param string $prefix Préfixe pour les clés
     */
    private function flattenArrayForMergeTags(array $data, array &$variables, string $prefix = ''): void
    {
        foreach ($data as $key => $value) {
            $newKey = $prefix ? $prefix . '.' . $key : $key;

            if (is_scalar($value)) {
                // Valeur simple : ajouter comme merge tag
                if (!isset($variables[$newKey])) {
                    $variables[$newKey] = \esc_html((string) $value);
                }
            } elseif (is_array($value) && !empty($value)) {
                // Tableau : continuer à aplatir
                $this->flattenArrayForMergeTags($value, $variables, $newKey);
            }
        }
    }
}
