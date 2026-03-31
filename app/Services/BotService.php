<?php
class BotService
{
    protected $config;
    protected $logger;
    protected $api;
    protected $lang;
    protected $store;
    protected $lock;
    protected $rate;
    protected $users;
    protected $settings;
    protected $cats;
    protected $items;
    protected $orders;
    protected $methods;
    protected $payments;
    protected $tickets;
    protected $ticketMessages;

    public function __construct(array $config)
    {
        $this->config = $config;
        date_default_timezone_set($config['timezone']);
        Utils::ensureDir($config['storage']['data_dir'], $config['storage']['dir_permissions']);
        Utils::ensureDir($config['storage']['lang_dir'], $config['storage']['dir_permissions']);
        Utils::ensureDir($config['storage']['log_dir'], $config['storage']['dir_permissions']);
        Utils::ensureDir($config['storage']['cache_dir'], $config['storage']['dir_permissions']);
        Utils::ensureDir($config['storage']['lock_dir'], $config['storage']['dir_permissions']);

        $this->logger = new Logger($config['storage']['log_dir'], !empty($config['security']['log_errors']));
        $this->store = new JsonStore($config['storage']['data_dir'], $config['storage']['file_permissions']);
        $this->lock = new FileLock($config['storage']['lock_dir']);
        $this->rate = new RateLimiter($this->store);
        $this->lang = new LanguageManager($config['storage']['lang_dir']);
        $this->api = new TelegramApi($config['bot_token']);
        $this->users = new UserRepository($this->store);
        $this->settings = new SettingRepository($this->store);
        $this->cats = new CategoryRepository($this->store);
        $this->items = new ItemRepository($this->store);
        $this->orders = new OrderRepository($this->store);
        $this->methods = new PaymentMethodRepository($this->store);
        $this->payments = new PaymentRequestRepository($this->store);
        $this->tickets = new TicketRepository($this->store);
        $this->ticketMessages = new TicketMessageRepository($this->store);
        $this->seedDefaults();
    }

    protected function seedDefaults()
    {
        $defaults = array(
            'banner_en' => 'Welcome to our premium account reseller bot.',
            'banner_fa' => 'به ربات فروش اکانت آماده خوش آمدید.',
            'help_en' => 'Use the menu buttons to browse categories, add credit, and manage your purchases.',
            'help_fa' => 'از دکمه‌های منو برای خرید، افزایش اعتبار و مدیریت خریدها استفاده کنید.',
            'contact_en' => 'Please send your message. Our admin will reply soon.',
            'contact_fa' => 'لطفاً پیام خود را ارسال کنید. ادمین به زودی پاسخ می‌دهد.',
            'support_username' => isset($this->config['bot']['support_username']) ? $this->config['bot']['support_username'] : '',
            'help_channel_url' => isset($this->config['bot']['help_channel_url']) ? $this->config['bot']['help_channel_url'] : '',
            'currency' => isset($this->config['bot']['currency']) ? $this->config['bot']['currency'] : 'USD',
            'payment_amount_min' => isset($this->config['security']['payment_amount_min']) ? (float)$this->config['security']['payment_amount_min'] : 0.10,
            'payment_amount_max' => isset($this->config['security']['payment_amount_max']) ? (float)$this->config['security']['payment_amount_max'] : 1000000,
            'low_stock_threshold' => 2,
            'sell_enabled' => 1,
            'sales_closed_text_en' => 'Sales are temporarily closed. Please wait until sales reopen.',
            'sales_closed_text_fa' => 'فروش موقتاً بسته است. لطفاً تا باز شدن فروش منتظر بمانید.',
            'maintenance_mode' => !empty($this->config['bot']['maintenance_mode']) ? 1 : 0,
            'maintenance_text_en' => isset($this->config['bot']['maintenance_text_en']) ? $this->config['bot']['maintenance_text_en'] : 'Bot is under maintenance. Please try again later.',
            'maintenance_text_fa' => isset($this->config['bot']['maintenance_text_fa']) ? $this->config['bot']['maintenance_text_fa'] : 'ربات در حال بروزرسانی است. بعداً دوباره تلاش کنید.',
        );
        foreach ($defaults as $k => $v) {
            if ($this->settings->get($k, null) === null) {
                $this->settings->set($k, $v);
            }
        }
    }

    public function run()
    {
        try {
            $this->guardRequest();
            $input = file_get_contents('php://input');
            if (!$input) {
                echo 'OK';
                return;
            }
            $update = json_decode($input, true);
            if (!is_array($update)) {
                echo 'OK';
                return;
            }
            if (isset($update['message'])) {
                $this->handleMessage($update['message']);
            } elseif (isset($update['callback_query'])) {
                $this->handleCallback($update['callback_query']);
            }
        } catch (Exception $e) {
            $this->logger->error($e->getMessage(), array('trace' => $e->getTraceAsString()));
        }
        echo 'OK';
    }

    protected function guardRequest()
    {
        if (!empty($this->config['security']['validate_webhook_secret'])) {
            $header = isset($_SERVER['HTTP_X_TELEGRAM_BOT_API_SECRET_TOKEN']) ? $_SERVER['HTTP_X_TELEGRAM_BOT_API_SECRET_TOKEN'] : '';
            if (!hash_equals($this->config['security']['webhook_secret'], $header)) {
                http_response_code(403);
                exit('Forbidden');
            }
        }
        $len = isset($_SERVER['CONTENT_LENGTH']) ? (int)$_SERVER['CONTENT_LENGTH'] : 0;
        if ($len > (int)$this->config['security']['request_size_limit']) {
            http_response_code(413);
            exit('Too Large');
        }
    }

    protected function handleMessage(array $message)
    {
        if (!empty($this->config['security']['strict_private_chat_only']) && (!isset($message['chat']['type']) || $message['chat']['type'] !== 'private')) {
            return;
        }

        $from = isset($message['from']) ? $message['from'] : array();
        $user = $this->ensureUser($from);
        $lang = $user['language'];
        $chatId = $message['chat']['id'];

        if (!$this->allowRate('global_user', $user['telegram_id'])) {
            return;
        }
        if ($this->isMaintenanceMode() && !$this->isAdmin($user['telegram_id'])) {
            $text = $lang === 'fa' ? $this->getRuntimeText('maintenance_text_fa', isset($this->config['bot']['maintenance_text_fa']) ? $this->config['bot']['maintenance_text_fa'] : '') : $this->getRuntimeText('maintenance_text_en', isset($this->config['bot']['maintenance_text_en']) ? $this->config['bot']['maintenance_text_en'] : '');
            $this->sendMessage($chatId, $text, null);
            return;
        }
        if (!empty($user['is_banned'])) {
            $this->sendMessage($chatId, sprintf($this->t($lang, 'banned'), Utils::h($user['ban_reason'])), null);
            return;
        }

        $text = isset($message['text']) ? Utils::safeText($message['text'], $this->config['security']['max_text_length']) : '';
        $photo = isset($message['photo']) ? $message['photo'] : null;

        if ($text === '/start') {
            $this->clearState($user['telegram_id']);
            $this->sendHome($chatId, $lang, $user['telegram_id']);
            return;
        }

        if ($this->isCancelInput($text, $lang)) {
            $this->clearState($user['telegram_id']);
            $this->sendHome($chatId, $lang, $user['telegram_id'], $this->t($lang, 'cancelled'));
            return;
        }

        if ($text === '/admin' && $this->isAdmin($user['telegram_id'])) {
            $this->showAdminPanel($chatId, $lang);
            return;
        }

        if ($this->isAdmin($user['telegram_id']) && $text === '/ban') {
            $this->setState($user['telegram_id'], 'admin_ban_wait', array());
            $this->sendMessage($chatId, $this->t($lang, 'send_ban_format'), $this->cancelKeyboard($lang));
            return;
        }

        if ($this->isAdmin($user['telegram_id']) && $text === '/unban') {
            $this->setState($user['telegram_id'], 'admin_unban_wait', array());
            $this->sendMessage($chatId, $this->t($lang, 'send_unban_id'), $this->cancelKeyboard($lang));
            return;
        }

        if ($this->isAdmin($user['telegram_id']) && $text === '/credit') {
            $this->setState($user['telegram_id'], 'admin_add_credit_wait', array());
            $this->sendMessage($chatId, $this->t($lang, 'admin_credit_format'), $this->cancelKeyboard($lang));
            return;
        }

        if ($this->isAdmin($user['telegram_id']) && preg_match('/^\/user\s+(.+)$/', $text, $m)) {
            $this->showAdminUserSearchResults($chatId, $lang, trim($m[1]));
            return;
        }

        if (($res = $this->handleState($user, $chatId, $lang, $text, $photo)) !== false) {
            return;
        }

        if ($this->isAdminReplyTicket($text, $user['telegram_id'])) {
            return;
        }

        if ($text === $this->t($lang, 'main_buy')) {
            $this->showBuyCategories($chatId, $lang);
            return;
        }
        if ($text === $this->t($lang, 'main_my_accounts')) {
            $this->showMyAccounts($chatId, $user, $lang);
            return;
        }
        if ($text === $this->t($lang, 'main_add_credit')) {
            $this->showPaymentMethods($chatId, $user, $lang);
            return;
        }
        if ($text === $this->t($lang, 'main_contact')) {
            $this->setState($user['telegram_id'], 'contact_wait', array());
            $this->sendMessage($chatId, $this->buildContactPromptText($lang), $this->cancelKeyboard($lang));
            return;
        }
        if ($text === $this->t($lang, 'main_help')) {
            $this->sendMessage($chatId, $this->buildHelpText($lang), $this->mainKeyboard($lang, $this->isAdmin($user['telegram_id'])));
            return;
        }
        if ($text === $this->t($lang, 'main_language')) {
            $this->sendMessage($chatId, $this->t($lang, 'language_select'), $this->languageKeyboard());
            return;
        }

        $this->sendMessage($chatId, $this->t($lang, 'unknown'), $this->mainKeyboard($lang, $this->isAdmin($user['telegram_id'])));
    }

    protected function handleCallback(array $callback)
    {
        $from = $callback['from'];
        $user = $this->ensureUser($from);
        $lang = $user['language'];
        $data = Utils::parseCallbackData($callback['data']);
        $chatId = $callback['message']['chat']['id'];
        $messageId = $callback['message']['message_id'];

        if (!$this->allowRate('global_user', $user['telegram_id'])) {
            return;
        }

        $this->api->answerCallbackQuery($callback['id'], 'OK');

        if ($data === 'lang:en' || $data === 'lang:fa') {
            $newLang = substr($data, 5);
            $this->users->saveByTelegramId($user['telegram_id'], array_merge($user, array('language' => $newLang, 'updated_at' => Utils::now())));
            $this->api->editMessageText($chatId, $messageId, $this->t($newLang, 'language_updated'), null);
            $this->sendHome($chatId, $newLang, $user['telegram_id']);
            return;
        }

        if ($data === 'buy:list') {
            $this->showBuyCategories($chatId, $lang);
            return;
        }
        if (strpos($data, 'buy:cat:') === 0) {
            $this->showCategoryDetail($chatId, (int)substr($data, 8), $lang);
            return;
        }
        if (strpos($data, 'buy:confirm:') === 0) {
            $this->processPurchase($chatId, $user, (int)substr($data, 12), $lang);
            return;
        }
        if (strpos($data, 'acc:') === 0) {
            $this->showAccountDetail($chatId, $user, (int)substr($data, 4), $lang);
            return;
        }
        if (strpos($data, 'paymethod:') === 0) {
            $this->showPaymentMethodPrompt($chatId, $user, (int)substr($data, 10), $lang);
            return;
        }

        if ($this->isAdmin($user['telegram_id'])) {
            if ($data === 'admin:panel') {
                $this->showAdminPanel($chatId, $lang);
                return;
            }
            if ($data === 'admin:add_category') {
                $this->setState($user['telegram_id'], 'admin_add_category_wait', array());
                $this->sendMessage($chatId, $this->t($lang, 'send_category_format'), $this->cancelKeyboard($lang));
                return;
            }
            if ($data === 'admin:list_categories') {
                $this->showAdminCategories($chatId, $lang);
                return;
            }
            if ($data === 'admin:add_method') {
                $this->setState($user['telegram_id'], 'admin_add_method_wait', array());
                $this->sendMessage($chatId, $this->t($lang, 'send_method_format'), $this->cancelKeyboard($lang));
                return;
            }
            if ($data === 'admin:methods') {
                $this->showAdminPaymentMethods($chatId, $lang);
                return;
            }
            if ($data === 'admin:pending_payments') {
                $this->showPendingPayments($chatId, $lang);
                return;
            }
            if ($data === 'admin:set_banner') {
                $this->setState($user['telegram_id'], 'admin_set_banner_wait', array());
                $this->sendMessage($chatId, $this->t($lang, 'admin_send_banner_prompt'), $this->cancelKeyboard($lang));
                return;
            }
            if ($data === 'admin:users') {
                $this->showAdminUsersPage($chatId, $lang, 1);
                return;
            }
            if ($data === 'admin:usersearch') {
                $this->setState($user['telegram_id'], 'admin_user_search_wait', array());
                $this->sendMessage($chatId, $this->t($lang, 'admin_user_search_prompt'), $this->cancelKeyboard($lang));
                return;
            }
            if ($data === 'admin:add_credit') {
                $this->setState($user['telegram_id'], 'admin_add_credit_wait', array());
                $this->sendMessage($chatId, $this->t($lang, 'admin_credit_format'), $this->cancelKeyboard($lang));
                return;
            }
            if ($data === 'admin:broadcast') {
                $this->showAdminBroadcastMenu($chatId, $lang);
                return;
            }
            if ($data === 'admin:settings') {
                $this->showAdminSettingsMenu($chatId, $lang);
                return;
            }
            if ($data === 'admin:sales_toggle') {
                $this->toggleSellEnabled($chatId, $lang);
                return;
            }
            if (strpos($data, 'admin:userpage:') === 0) {
                $this->showAdminUsersPage($chatId, $lang, (int)substr($data, 15));
                return;
            }
            if (strpos($data, 'admin:user:') === 0) {
                $this->showAdminUserDetail($chatId, $lang, (int)substr($data, 11));
                return;
            }
            if (strpos($data, 'admin:userban:') === 0) {
                $targetId = (int)substr($data, 14);
                $this->setState($user['telegram_id'], 'admin_user_ban_reason_wait', array('target_telegram_id' => $targetId));
                $this->sendMessage($chatId, $this->t($lang, 'admin_user_ban_reason_prompt'), $this->cancelKeyboard($lang));
                return;
            }
            if (strpos($data, 'admin:userunban:') === 0) {
                $this->adminUnbanUser($chatId, $lang, (int)substr($data, 16));
                return;
            }
            if (strpos($data, 'admin:usercredit:') === 0) {
                $targetId = (int)substr($data, 17);
                $this->setState($user['telegram_id'], 'admin_user_credit_amount_wait', array('target_telegram_id' => $targetId));
                $this->sendMessage($chatId, $this->t($lang, 'admin_user_credit_amount_prompt'), $this->cancelKeyboard($lang));
                return;
            }
            if (strpos($data, 'admin:useraccounts:') === 0) {
                $this->showAdminUserAccounts($chatId, $lang, (int)substr($data, 19));
                return;
            }
            if (strpos($data, 'admin:announce:') === 0) {
                $target = substr($data, 15);
                $this->setState($user['telegram_id'], 'admin_announce_wait', array('target' => $target));
                $this->sendMessage($chatId, sprintf($this->t($lang, 'admin_announce_prompt'), Utils::h($this->broadcastTargetLabel($target, $lang))), $this->cancelKeyboard($lang));
                return;
            }
            if (strpos($data, 'admin:setbool:') === 0) {
                $key = substr($data, 14);
                $this->toggleRuntimeBooleanSetting($chatId, $lang, $key);
                return;
            }
            if (strpos($data, 'admin:setsingle:') === 0) {
                $key = substr($data, 16);
                $this->setState($user['telegram_id'], 'admin_setting_single_wait', array('setting_key' => $key));
                $this->sendMessage($chatId, sprintf($this->t($lang, 'admin_setting_single_prompt'), Utils::h($key)), $this->cancelKeyboard($lang));
                return;
            }
            if (strpos($data, 'admin:setpair:') === 0) {
                $key = substr($data, 14);
                $this->setState($user['telegram_id'], 'admin_setting_pair_wait', array('setting_key' => $key));
                $this->sendMessage($chatId, sprintf($this->t($lang, 'admin_setting_pair_prompt'), Utils::h($key)), $this->cancelKeyboard($lang));
                return;
            }
            if (strpos($data, 'admin:catmanage:') === 0) {
                $this->showAdminCategoryManage($chatId, (int)substr($data, 16), $lang);
                return;
            }
            if (strpos($data, 'admin:cataddstock:') === 0) {
                $cid = (int)substr($data, 18);
                $this->setState($user['telegram_id'], 'admin_add_stock_wait', array('category_id' => $cid));
                $this->sendMessage($chatId, $this->t($lang, 'send_stock_bulk'), $this->cancelKeyboard($lang));
                return;
            }
            if (strpos($data, 'admin:catedit:') === 0) {
                $cid = (int)substr($data, 14);
                $this->setState($user['telegram_id'], 'admin_edit_category_wait', array('category_id' => $cid));
                $this->sendMessage($chatId, $this->t($lang, 'send_category_edit_format'), $this->cancelKeyboard($lang));
                return;
            }
            if (strpos($data, 'admin:catdelete:') === 0) {
                $cid = (int)substr($data, 16);
                $this->deleteCategory($chatId, $cid, $lang);
                return;
            }
            if (strpos($data, 'admin:method:') === 0) {
                $this->showAdminPaymentMethodManage($chatId, $lang, (int)substr($data, 13));
                return;
            }
            if (strpos($data, 'admin:methodedit:') === 0) {
                $mid = (int)substr($data, 17);
                $this->setState($user['telegram_id'], 'admin_edit_method_wait', array('method_id' => $mid));
                $this->sendMessage($chatId, $this->t($lang, 'send_method_edit_format'), $this->cancelKeyboard($lang));
                return;
            }
            if (strpos($data, 'admin:methoddelete:') === 0) {
                $this->deletePaymentMethod($chatId, $lang, (int)substr($data, 19));
                return;
            }
            if (strpos($data, 'admin:methodtoggle:') === 0) {
                $this->togglePaymentMethod($chatId, $lang, (int)substr($data, 19));
                return;
            }
            if (strpos($data, 'payapprove:') === 0) {
                $this->approvePayment((int)substr($data, 11), $user['telegram_id']);
                $this->sendMessage($chatId, $this->t($lang, 'payment_processed'), null);
                return;
            }
            if (strpos($data, 'payreject:') === 0) {
                $this->rejectPayment((int)substr($data, 10), $user['telegram_id'], 'Rejected by admin');
                $this->sendMessage($chatId, $this->t($lang, 'payment_processed'), null);
                return;
            }
            if (strpos($data, 'payeditapprove:') === 0) {
                $paymentId = (int)substr($data, 15);
                $this->setState($user['telegram_id'], 'admin_payment_edit_amount_wait', array('payment_id' => $paymentId));
                $this->sendMessage($chatId, $this->t($lang, 'admin_edit_amount_prompt'), $this->cancelKeyboard($lang));
                return;
            }
        }
    }

    protected function handleState(array $user, $chatId, $lang, $text, $photo)
    {
        $state = $user['state'];
        $data = is_array($user['state_data']) ? $user['state_data'] : array();
        if (!$state) {
            return false;
        }
        if (!empty($user['state_expires_at']) && $user['state_expires_at'] < time()) {
            $this->clearState($user['telegram_id']);
            return false;
        }

        switch ($state) {
            case 'contact_wait':
                if (!$this->allowRate('contact', $user['telegram_id'])) {
                    return true;
                }
                if ($text !== '') {
                    $this->createTicketAndNotifyAdmins($user, $text);
                    $this->clearState($user['telegram_id']);
                    $this->sendMessage($chatId, $this->t($lang, 'contact_sent'), $this->mainKeyboard($lang, $this->isAdmin($user['telegram_id'])));
                }
                return true;

            case 'payment_receipt_wait':
                if ($photo) {
                    $last = end($photo);
                    $data['receipt_type'] = 'photo';
                    $data['receipt_value'] = $last['file_id'];
                } elseif ($text !== '') {
                    $data['receipt_type'] = 'text';
                    $data['receipt_value'] = $text;
                } else {
                    $this->sendMessage($chatId, $this->t($lang, 'receipt_missing'), $this->cancelKeyboard($lang));
                    return true;
                }
                $this->setState($user['telegram_id'], 'payment_amount_wait', $data);
                $this->sendMessage($chatId, sprintf($this->t($lang, 'amount_missing'), Utils::fmtMoney($this->paymentMin()), $this->runtimeCurrency(), Utils::fmtMoney($this->paymentMax()), $this->runtimeCurrency()), $this->cancelKeyboard($lang));
                return true;

            case 'payment_amount_wait':
                if (!$this->allowRate('payment_submit', $user['telegram_id'])) {
                    return true;
                }
                $normalized = Utils::normalizeAmountInput($text);
                if ($normalized === null) {
                    $this->sendMessage($chatId, $this->t($lang, 'amount_invalid'), $this->cancelKeyboard($lang));
                    return true;
                }
                $amount = (float)$normalized;
                if ($amount < $this->paymentMin() || $amount > $this->paymentMax()) {
                    $this->sendMessage($chatId, $this->t($lang, 'amount_invalid'), $this->cancelKeyboard($lang));
                    return true;
                }
                $payment = $this->payments->insert(array(
                    'user_telegram_id' => $user['telegram_id'],
                    'method_id' => $data['method_id'],
                    'amount' => $amount,
                    'receipt_type' => $data['receipt_type'],
                    'receipt_value' => $data['receipt_value'],
                    'note' => isset($data['note']) ? $data['note'] : '',
                    'status' => 'pending',
                    'admin_note' => '',
                    'created_at' => Utils::now(),
                    'updated_at' => Utils::now()
                ));
                $this->notifyAdminsPayment($payment['id']);
                $this->clearState($user['telegram_id']);
                $this->sendMessage($chatId, $this->t($lang, 'payment_request_saved'), $this->mainKeyboard($lang, $this->isAdmin($user['telegram_id'])));
                return true;

            case 'admin_payment_edit_amount_wait':
                if (!$this->isAdmin($user['telegram_id'])) {
                    return true;
                }
                $normalized = Utils::normalizeAmountInput($text);
                if ($normalized === null) {
                    $this->sendMessage($chatId, $this->t($lang, 'amount_invalid'), $this->cancelKeyboard($lang));
                    return true;
                }
                $amount = (float)$normalized;
                if ($amount < $this->paymentMin() || $amount > $this->paymentMax()) {
                    $this->sendMessage($chatId, $this->t($lang, 'amount_invalid'), $this->cancelKeyboard($lang));
                    return true;
                }
                $this->approvePayment((int)$data['payment_id'], $user['telegram_id'], $amount, true);
                $this->clearState($user['telegram_id']);
                $this->sendMessage($chatId, $this->t($lang, 'payment_processed'), null);
                return true;

            case 'admin_user_search_wait':
                if (!$this->isAdmin($user['telegram_id'])) {
                    return true;
                }
                $this->clearState($user['telegram_id']);
                $this->showAdminUserSearchResults($chatId, $lang, $text);
                return true;

            case 'admin_add_credit_wait':
                if (!$this->isAdmin($user['telegram_id'])) {
                    return true;
                }
                $parts = explode('|', $text, 3);
                if (count($parts) < 2) {
                    $this->sendMessage($chatId, $this->t($lang, 'invalid_format'), $this->cancelKeyboard($lang));
                    return true;
                }
                $targetId = trim($parts[0]);
                $amountNormalized = Utils::normalizeAmountInput($parts[1]);
                if ($amountNormalized === null) {
                    $this->sendMessage($chatId, $this->t($lang, 'amount_invalid'), $this->cancelKeyboard($lang));
                    return true;
                }
                $note = isset($parts[2]) ? trim($parts[2]) : '';
                $this->creditUserByAdmin($chatId, $lang, $targetId, (float)$amountNormalized, $note, $user['telegram_id']);
                $this->clearState($user['telegram_id']);
                return true;

            case 'admin_user_credit_amount_wait':
                if (!$this->isAdmin($user['telegram_id'])) {
                    return true;
                }
                $amountNormalized = Utils::normalizeAmountInput($text);
                if ($amountNormalized === null) {
                    $this->sendMessage($chatId, $this->t($lang, 'amount_invalid'), $this->cancelKeyboard($lang));
                    return true;
                }
                $this->creditUserByAdmin($chatId, $lang, $data['target_telegram_id'], (float)$amountNormalized, '', $user['telegram_id']);
                $this->clearState($user['telegram_id']);
                return true;

            case 'admin_user_ban_reason_wait':
                if (!$this->isAdmin($user['telegram_id'])) {
                    return true;
                }
                $this->adminBanUser($chatId, $lang, $data['target_telegram_id'], $text);
                $this->clearState($user['telegram_id']);
                return true;

            case 'admin_announce_wait':
                if (!$this->isAdmin($user['telegram_id'])) {
                    return true;
                }
                $this->broadcastAnnouncement($chatId, $lang, isset($data['target']) ? $data['target'] : 'all', $text);
                $this->clearState($user['telegram_id']);
                return true;

            case 'admin_setting_single_wait':
                if (!$this->isAdmin($user['telegram_id'])) {
                    return true;
                }
                $value = trim($text);
                if (in_array($data['setting_key'], array('payment_amount_min', 'payment_amount_max'), true)) {
                    $normalized = Utils::normalizeAmountInput($value);
                    if ($normalized === null) {
                        $this->sendMessage($chatId, $this->t($lang, 'amount_invalid'), $this->cancelKeyboard($lang));
                        return true;
                    }
                    $value = $normalized;
                }
                $this->settings->set($data['setting_key'], $value);
                $this->clearState($user['telegram_id']);
                $this->sendMessage($chatId, sprintf($this->t($lang, 'setting_updated'), Utils::h($data['setting_key'])), $this->mainKeyboard($lang, true));
                return true;

            case 'admin_setting_pair_wait':
                if (!$this->isAdmin($user['telegram_id'])) {
                    return true;
                }
                $parts = explode('|', $text, 2);
                if (count($parts) !== 2) {
                    $this->sendMessage($chatId, $this->t($lang, 'invalid_format'), $this->cancelKeyboard($lang));
                    return true;
                }
                $this->settings->set($data['setting_key'] . '_en', trim($parts[0]));
                $this->settings->set($data['setting_key'] . '_fa', trim($parts[1]));
                $this->clearState($user['telegram_id']);
                $this->sendMessage($chatId, sprintf($this->t($lang, 'setting_updated'), Utils::h($data['setting_key'])), $this->mainKeyboard($lang, true));
                return true;

            case 'admin_add_category_wait':
                if (!$this->isAdmin($user['telegram_id'])) {
                    return true;
                }
                $parts = explode('|', $text, 5);
                if (count($parts) !== 5) {
                    $this->sendMessage($chatId, $this->t($lang, 'invalid_format'), $this->cancelKeyboard($lang));
                    return true;
                }
                $this->cats->insert(array(
                    'title_en' => trim($parts[0]),
                    'title_fa' => trim($parts[1]),
                    'price' => (float)trim($parts[2]),
                    'description_en' => trim($parts[3]),
                    'description_fa' => trim($parts[4]),
                    'is_active' => 1,
                    'is_deleted' => 0,
                    'created_at' => Utils::now(),
                    'updated_at' => Utils::now(),
                    'deleted_at' => ''
                ));
                $this->clearState($user['telegram_id']);
                $this->sendMessage($chatId, $this->t($lang, 'category_added'), $this->mainKeyboard($lang, true));
                return true;

            case 'admin_add_stock_wait':
                if (!$this->isAdmin($user['telegram_id'])) {
                    return true;
                }
                $count = 0;
                foreach (Utils::explodeLines($text) as $line) {
                    $line = trim($line);
                    if ($line === '') {
                        continue;
                    }
                    $this->items->insert(array(
                        'category_id' => $data['category_id'],
                        'content' => $line,
                        'status' => 'available',
                        'assigned_to' => null,
                        'assigned_at' => '',
                        'created_at' => Utils::now()
                    ));
                    $count++;
                }
                $this->refreshLowStockAlertState($data['category_id']);
                $this->clearState($user['telegram_id']);
                $this->sendMessage($chatId, $this->t($lang, 'stock_added') . ' (' . $count . ')', $this->mainKeyboard($lang, true));
                return true;

            case 'admin_edit_category_wait':
                if (!$this->isAdmin($user['telegram_id'])) {
                    return true;
                }
                $parts = explode('|', $text, 5);
                if (count($parts) !== 5) {
                    $this->sendMessage($chatId, $this->t($lang, 'invalid_format'), $this->cancelKeyboard($lang));
                    return true;
                }
                $this->cats->updateById($data['category_id'], array(
                    'title_en' => trim($parts[0]),
                    'title_fa' => trim($parts[1]),
                    'price' => (float)trim($parts[2]),
                    'description_en' => trim($parts[3]),
                    'description_fa' => trim($parts[4]),
                    'updated_at' => Utils::now(),
                    'is_deleted' => 0
                ));
                $this->clearState($user['telegram_id']);
                $this->sendMessage($chatId, $this->t($lang, 'category_updated'), $this->mainKeyboard($lang, true));
                return true;

            case 'admin_add_method_wait':
                if (!$this->isAdmin($user['telegram_id'])) {
                    return true;
                }
                $parts = explode('|', $text, 4);
                if (count($parts) !== 4) {
                    $this->sendMessage($chatId, $this->t($lang, 'invalid_format'), $this->cancelKeyboard($lang));
                    return true;
                }
                $this->methods->insert(array(
                    'title_en' => trim($parts[0]),
                    'title_fa' => trim($parts[1]),
                    'details_en' => trim($parts[2]),
                    'details_fa' => trim($parts[3]),
                    'is_active' => 1,
                    'is_deleted' => 0,
                    'sort_order' => 0,
                    'created_at' => Utils::now(),
                    'updated_at' => Utils::now(),
                    'deleted_at' => ''
                ));
                $this->clearState($user['telegram_id']);
                $this->sendMessage($chatId, $this->t($lang, 'method_added'), $this->mainKeyboard($lang, true));
                return true;

            case 'admin_edit_method_wait':
                if (!$this->isAdmin($user['telegram_id'])) {
                    return true;
                }
                $parts = explode('|', $text, 4);
                if (count($parts) !== 4) {
                    $this->sendMessage($chatId, $this->t($lang, 'invalid_format'), $this->cancelKeyboard($lang));
                    return true;
                }
                $method = $this->methods->findById($data['method_id']);
                if (!$method) {
                    $this->clearState($user['telegram_id']);
                    $this->sendMessage($chatId, $this->t($lang, 'method_not_found'), $this->mainKeyboard($lang, true));
                    return true;
                }
                $this->methods->updateById($data['method_id'], array(
                    'title_en' => trim($parts[0]),
                    'title_fa' => trim($parts[1]),
                    'details_en' => trim($parts[2]),
                    'details_fa' => trim($parts[3]),
                    'updated_at' => Utils::now(),
                    'is_deleted' => 0
                ));
                $this->clearState($user['telegram_id']);
                $this->sendMessage($chatId, $this->t($lang, 'method_updated'), $this->mainKeyboard($lang, true));
                return true;

            case 'admin_set_banner_wait':
                if (!$this->isAdmin($user['telegram_id'])) {
                    return true;
                }
                $parts = explode('|', $text, 2);
                if (count($parts) !== 2) {
                    $this->sendMessage($chatId, $this->t($lang, 'invalid_format'), $this->cancelKeyboard($lang));
                    return true;
                }
                $this->settings->set('banner_en', trim($parts[0]));
                $this->settings->set('banner_fa', trim($parts[1]));
                $this->clearState($user['telegram_id']);
                $this->sendMessage($chatId, $this->t($lang, 'banner_updated'), $this->mainKeyboard($lang, true));
                return true;

            case 'admin_ban_wait':
                if (!$this->isAdmin($user['telegram_id'])) {
                    return true;
                }
                $parts = explode('|', $text, 2);
                if (count($parts) !== 2) {
                    $this->sendMessage($chatId, $this->t($lang, 'invalid_format'), $this->cancelKeyboard($lang));
                    return true;
                }
                $target = $this->users->findByTelegramId(trim($parts[0]));
                if (!$target) {
                    $this->sendMessage($chatId, $this->t($lang, 'user_not_found'), $this->cancelKeyboard($lang));
                    return true;
                }
                $this->users->saveByTelegramId($target['telegram_id'], array_merge($target, array('is_banned' => 1, 'ban_reason' => trim($parts[1]), 'updated_at' => Utils::now())));
                $this->clearState($user['telegram_id']);
                $this->sendMessage($chatId, $this->t($lang, 'user_banned'), $this->mainKeyboard($lang, true));
                return true;

            case 'admin_unban_wait':
                if (!$this->isAdmin($user['telegram_id'])) {
                    return true;
                }
                $target = $this->users->findByTelegramId(trim($text));
                if (!$target) {
                    $this->sendMessage($chatId, $this->t($lang, 'user_not_found'), $this->cancelKeyboard($lang));
                    return true;
                }
                $this->users->saveByTelegramId($target['telegram_id'], array_merge($target, array('is_banned' => 0, 'ban_reason' => '', 'updated_at' => Utils::now())));
                $this->clearState($user['telegram_id']);
                $this->sendMessage($chatId, $this->t($lang, 'user_unbanned'), $this->mainKeyboard($lang, true));
                return true;
        }

        return false;
    }

    protected function sendHome($chatId, $lang, $telegramId, $prefix = '')
    {
        $banner = $this->settings->get('banner_' . $lang, '');
        $text = sprintf($this->t($lang, 'welcome'), Utils::h($banner));
        if ($prefix !== '') {
            $text = $prefix . "\n\n" . $text;
        }
        $this->sendMessage($chatId, $text, $this->mainKeyboard($lang, $this->isAdmin($telegramId)));
    }

    protected function ensureUser(array $from)
    {
        $telegramId = isset($from['id']) ? $from['id'] : 0;
        $user = $this->users->findByTelegramId($telegramId);
        $record = array(
            'telegram_id' => $telegramId,
            'username' => isset($from['username']) ? Utils::sanitizeUsername($from['username']) : '',
            'first_name' => isset($from['first_name']) ? Utils::safeText($from['first_name'], 120) : '',
            'last_name' => isset($from['last_name']) ? Utils::safeText($from['last_name'], 120) : '',
            'language' => $user ? $user['language'] : $this->config['bot']['default_language'],
            'credit' => $user ? $user['credit'] : 0,
            'is_banned' => $user ? $user['is_banned'] : 0,
            'ban_reason' => $user ? $user['ban_reason'] : '',
            'state' => $user ? $user['state'] : '',
            'state_data' => $user ? $user['state_data'] : array(),
            'state_expires_at' => $user ? $user['state_expires_at'] : 0,
            'created_at' => $user ? $user['created_at'] : Utils::now(),
            'updated_at' => Utils::now()
        );
        return $this->users->saveByTelegramId($telegramId, $record);
    }

    protected function setState($telegramId, $state, array $data)
    {
        $user = $this->users->findByTelegramId($telegramId);
        if (!$user) {
            return;
        }
        $user['state'] = $state;
        $user['state_data'] = $data;
        $user['state_expires_at'] = time() + (int)$this->config['security']['state_ttl'];
        $user['updated_at'] = Utils::now();
        $this->users->saveByTelegramId($telegramId, $user);
    }

    protected function clearState($telegramId)
    {
        $user = $this->users->findByTelegramId($telegramId);
        if (!$user) {
            return;
        }
        $user['state'] = '';
        $user['state_data'] = array();
        $user['state_expires_at'] = 0;
        $user['updated_at'] = Utils::now();
        $this->users->saveByTelegramId($telegramId, $user);
    }

    protected function t($lang, $key)
    {
        return $this->lang->t($lang, $key);
    }

    protected function isAdmin($telegramId)
    {
        return in_array((int)$telegramId, array_map('intval', $this->config['admin_ids']), true);
    }

    protected function allowRate($bucket, $telegramId)
    {
        if (!isset($this->config['rate_limits'][$bucket])) {
            return true;
        }
        $cfg = $this->config['rate_limits'][$bucket];
        list($ok,) = $this->rate->hit($bucket . ':' . $telegramId, $cfg['limit'], $cfg['window']);
        return $ok;
    }

    protected function sendMessage($chatId, $text, $markup)
    {
        $text = Utils::safeText($text, $this->config['security']['max_text_length']);
        return $this->api->sendMessage($chatId, $text, $markup);
    }

    protected function mainKeyboard($lang, $isAdmin)
    {
        $kb = array(
            array($this->t($lang, 'main_buy'), $this->t($lang, 'main_my_accounts')),
            array($this->t($lang, 'main_add_credit'), $this->t($lang, 'main_contact')),
            array($this->t($lang, 'main_help'), $this->t($lang, 'main_language')),
        );
        if ($isAdmin) {
            $kb[] = array('/admin');
        }
        return array('keyboard' => $kb, 'resize_keyboard' => true, 'is_persistent' => true);
    }

    protected function cancelKeyboard($lang)
    {
        return array(
            'keyboard' => array(array($this->t($lang, 'cancel_process'))),
            'resize_keyboard' => true,
            'one_time_keyboard' => true,
            'is_persistent' => false
        );
    }

    protected function languageKeyboard()
    {
        return array('inline_keyboard' => array(array(array('text' => 'English', 'callback_data' => 'lang:en'), array('text' => 'فارسی', 'callback_data' => 'lang:fa'))));
    }

    protected function isCancelInput($text, $lang)
    {
        if ($text === '/cancel') {
            return true;
        }
        if ($text === $this->t($lang, 'cancel_process')) {
            return true;
        }
        if ($text === $this->t('en', 'cancel_process')) {
            return true;
        }
        if ($text === $this->t('fa', 'cancel_process')) {
            return true;
        }
        return false;
    }

    protected function showBuyCategories($chatId, $lang)
    {
        if (!$this->sellEnabled()) {
            $this->sendMessage($chatId, $this->salesClosedText($lang), null);
            return;
        }
        $buttons = array();
        foreach ($this->cats->active() as $cat) {
            $stock = count($this->items->availableByCategory($cat['id']));
            if ($stock < 1) {
                continue;
            }
            $title = $lang === 'fa' ? $cat['title_fa'] : $cat['title_en'];
            $buttons[] = array(array('text' => $title . ' (' . $stock . ')', 'callback_data' => 'buy:cat:' . $cat['id']));
        }
        if (!$buttons) {
            $this->sendMessage($chatId, $this->t($lang, 'no_categories'), null);
            return;
        }
        $this->sendMessage($chatId, $this->t($lang, 'choose_category'), array('inline_keyboard' => $buttons));
    }

    protected function showCategoryDetail($chatId, $categoryId, $lang)
    {
        $cat = $this->cats->findById($categoryId);
        if (!$cat || empty($cat['is_active'])) {
            $this->sendMessage($chatId, $this->t($lang, 'purchase_failed'), null);
            return;
        }
        $stock = count($this->items->availableByCategory($categoryId));
        if ($stock < 1) {
            $this->sendMessage($chatId, $this->t($lang, 'purchase_failed'), null);
            return;
        }
        $title = $lang === 'fa' ? $cat['title_fa'] : $cat['title_en'];
        $desc = $lang === 'fa' ? $cat['description_fa'] : $cat['description_en'];
        $txt = sprintf($this->t($lang, 'category_info'), Utils::h($title), Utils::fmtMoney($cat['price']), $this->runtimeCurrency(), $stock, Utils::h($desc));
        $kb = array('inline_keyboard' => array(
            array(array('text' => $this->t($lang, 'buy_confirm'), 'callback_data' => 'buy:confirm:' . $categoryId)),
            array(array('text' => $this->t($lang, 'back'), 'callback_data' => 'buy:list'))
        ));
        $this->sendMessage($chatId, $txt, $kb);
    }

    protected function processPurchase($chatId, array $user, $categoryId, $lang)
    {
        if (!$this->allowRate('buy', $user['telegram_id'])) {
            return;
        }
        if (!$this->sellEnabled() && !$this->isAdmin($user['telegram_id'])) {
            $this->sendMessage($chatId, $this->salesClosedText($lang), null);
            return;
        }
        $lock = $this->lock->acquire('purchase:' . $categoryId);
        try {
            $cat = $this->cats->findById($categoryId);
            $user = $this->users->findByTelegramId($user['telegram_id']);
            if (!$cat || empty($cat['is_active'])) {
                $this->sendMessage($chatId, $this->t($lang, 'purchase_failed'), null);
                return;
            }
            if ((float)$user['credit'] < (float)$cat['price']) {
                $this->sendMessage($chatId, sprintf($this->t($lang, 'not_enough_credit'), Utils::fmtMoney($user['credit']), $this->runtimeCurrency()), null);
                return;
            }
            $available = $this->items->availableByCategory($categoryId);
            if (!$available) {
                $this->sendMessage($chatId, $this->t($lang, 'purchase_failed'), null);
                return;
            }
            $item = $available[0];
            $item['status'] = 'sold';
            $item['assigned_to'] = $user['telegram_id'];
            $item['assigned_at'] = Utils::now();
            $this->items->updateById($item['id'], $item);

            $user['credit'] = round(((float)$user['credit'] - (float)$cat['price']), 2);
            $user['updated_at'] = Utils::now();
            $this->users->saveByTelegramId($user['telegram_id'], $user);

            $order = $this->orders->insert(array(
                'user_telegram_id' => $user['telegram_id'],
                'category_id' => $categoryId,
                'item_id' => $item['id'],
                'price' => $cat['price'],
                'category_title_en' => isset($cat['title_en']) ? $cat['title_en'] : '',
                'category_title_fa' => isset($cat['title_fa']) ? $cat['title_fa'] : '',
                'item_content' => isset($item['content']) ? $item['content'] : '',
                'created_at' => Utils::now()
            ));

            $this->maybeNotifyLowStock($categoryId);

            $title = $lang === 'fa' ? $cat['title_fa'] : $cat['title_en'];
            $this->sendMessage($chatId, sprintf($this->t($lang, 'purchase_success'), Utils::h($title), Utils::fmtMoney($cat['price']), $this->runtimeCurrency(), Utils::h($item['content'])), $this->mainKeyboard($lang, $this->isAdmin($user['telegram_id'])));
            $this->logger->info('Purchase', array('order_id' => $order['id'], 'user' => $user['telegram_id'], 'category' => $categoryId));
        } finally {
            $this->lock->release($lock);
        }
    }

    protected function showMyAccounts($chatId, array $user, $lang)
    {
        $rows = $this->orders->byUser($user['telegram_id']);
        if (!$rows) {
            $this->sendMessage($chatId, $this->t($lang, 'my_accounts_empty'), null);
            return;
        }
        $buttons = array();
        foreach ($rows as $row) {
            $title = $this->orderCategoryTitle($row, $lang);
            $buttons[] = array(array('text' => '#' . $row['id'] . ' - ' . $title, 'callback_data' => 'acc:' . $row['id']));
        }
        $this->sendMessage($chatId, $this->t($lang, 'my_accounts_list'), array('inline_keyboard' => $buttons));
    }

    protected function showAccountDetail($chatId, array $user, $orderId, $lang)
    {
        $order = null;
        foreach ($this->orders->byUser($user['telegram_id']) as $row) {
            if ((string)$row['id'] === (string)$orderId) {
                $order = $row;
                break;
            }
        }
        if (!$order) {
            $this->sendMessage($chatId, $this->t($lang, 'purchase_failed'), null);
            return;
        }
        $title = $this->orderCategoryTitle($order, $lang);
        $content = $this->orderItemContent($order);
        $this->sendMessage($chatId, sprintf($this->t($lang, 'account_detail'), Utils::h($title), Utils::fmtMoney($order['price']), $this->runtimeCurrency(), $order['created_at']), null);
        $this->sendAccountItemContent($chatId, $lang, $content);
    }

    protected function sendAccountItemContent($chatId, $lang, $content)
    {
        $content = trim((string)$content);
        if ($content === '') {
            return;
        }
        if (Utils::looksLikeLink($content)) {
            $this->sendMessage($chatId, $this->t($lang, 'account_link_label') . "\n" . Utils::h($content), null);
            return;
        }
        $this->sendMessage($chatId, $this->t($lang, 'account_link_label') . "\n<code>" . Utils::h($content) . "</code>", null);
    }

    protected function showPaymentMethods($chatId, array $user, $lang)
    {
        $methods = $this->methods->active();
        if (!$methods) {
            $this->sendMessage($chatId, $this->t($lang, 'payment_methods_empty'), null);
            return;
        }
        $buttons = array();
        foreach ($methods as $m) {
            $title = $lang === 'fa' ? $m['title_fa'] : $m['title_en'];
            $buttons[] = array(array('text' => $title, 'callback_data' => 'paymethod:' . $m['id']));
        }
        $this->sendMessage($chatId, sprintf($this->t($lang, 'credit_title'), Utils::fmtMoney($user['credit']), $this->runtimeCurrency()), array('inline_keyboard' => $buttons));
    }

    protected function showPaymentMethodPrompt($chatId, array $user, $methodId, $lang)
    {
        $method = $this->methods->findById($methodId);
        if (!$method || empty($method['is_active'])) {
            return;
        }
        $title = $lang === 'fa' ? $method['title_fa'] : $method['title_en'];
        $details = $lang === 'fa' ? $method['details_fa'] : $method['details_en'];
        $this->setState($user['telegram_id'], 'payment_receipt_wait', array('method_id' => $methodId));
        $this->sendMessage(
            $chatId,
            sprintf(
                $this->t($lang, 'payment_method_info'),
                Utils::h($title),
                $this->runtimeCurrency(),
                Utils::fmtMoney($this->paymentMin()),
                $this->runtimeCurrency(),
                Utils::fmtMoney($this->paymentMax()),
                $this->runtimeCurrency(),
                Utils::h($details)
            ),
            $this->cancelKeyboard($lang)
        );
    }

    protected function buildContactPromptText($lang)
    {
        $parts = array();
        $base = $this->getRuntimeTextFilled('contact_' . $lang, $this->t($lang, 'contact_prompt'));
        if ($base !== '') {
            $parts[] = Utils::h($base);
        } else {
            $parts[] = $this->t($lang, 'contact_prompt');
        }
        $support = $this->getRuntimeTextFilled('support_username', isset($this->config['bot']['support_username']) ? $this->config['bot']['support_username'] : '');
        if (!empty($support)) {
            $parts[] = sprintf($this->t($lang, 'contact_support_line'), Utils::h($support));
        }
        return implode("\n\n", $parts);
    }

    protected function buildHelpText($lang)
    {
        $parts = array();
        $help = $this->getRuntimeTextFilled('help_' . $lang, '');
        if ($help !== '') {
            $parts[] = Utils::h($help);
        }
        $channel = $this->getRuntimeTextFilled('help_channel_url', isset($this->config['bot']['help_channel_url']) ? $this->config['bot']['help_channel_url'] : '');
        if (!empty($channel)) {
            $parts[] = sprintf($this->t($lang, 'help_channel_line'), Utils::h($channel));
        }
        return implode("\n\n", $parts);
    }

    protected function createTicketAndNotifyAdmins(array $user, $message)
    {
        $ticket = $this->tickets->insert(array(
            'user_telegram_id' => $user['telegram_id'],
            'admin_telegram_id' => 0,
            'subject' => '',
            'message' => $message,
            'status' => 'open',
            'created_at' => Utils::now(),
            'updated_at' => Utils::now()
        ));
        $this->ticketMessages->insert(array(
            'ticket_id' => $ticket['id'],
            'sender_type' => 'user',
            'sender_telegram_id' => $user['telegram_id'],
            'message' => $message,
            'created_at' => Utils::now()
        ));
        $name = trim($user['first_name'] . ' (@' . $user['username'] . ')');
        foreach ($this->config['admin_ids'] as $adminId) {
            $copy = sprintf($this->t('en', 'contact_admin_copy'), Utils::h($name), $user['telegram_id'], Utils::h($message)) . "\n\nReply format:\n<code>" . $this->config['security']['admin_reply_prefix'] . $ticket['id'] . " your message</code>";
            $this->sendMessage($adminId, $copy, null);
        }
    }

    protected function isAdminReplyTicket($text, $adminTelegramId)
    {
        if (!$this->isAdmin($adminTelegramId)) {
            return false;
        }
        $prefix = preg_quote($this->config['security']['admin_reply_prefix'], '/');
        if (!preg_match('/^' . $prefix . '([0-9]+)\s+(.+)$/s', $text, $m)) {
            return false;
        }
        $ticket = $this->tickets->findById((int)$m[1]);
        if (!$ticket) {
            return true;
        }
        $reply = trim($m[2]);
        $this->ticketMessages->insert(array('ticket_id' => $ticket['id'], 'sender_type' => 'admin', 'sender_telegram_id' => $adminTelegramId, 'message' => $reply, 'created_at' => Utils::now()));
        $ticket['admin_telegram_id'] = $adminTelegramId;
        $ticket['updated_at'] = Utils::now();
        $this->tickets->updateById($ticket['id'], $ticket);
        $user = $this->users->findByTelegramId($ticket['user_telegram_id']);
        if ($user) {
            $this->sendMessage($user['telegram_id'], sprintf($this->t($user['language'], 'ticket_reply_prefix'), Utils::h($reply)), null);
        }
        return true;
    }

    protected function notifyAdminsPayment($paymentId)
    {
        $payment = $this->payments->findById($paymentId);
        if (!$payment) {
            return;
        }
        $user = $this->users->findByTelegramId($payment['user_telegram_id']);
        $method = $this->methods->findById($payment['method_id']);
        $name = trim($user['first_name'] . ' (@' . $user['username'] . ')');
        $caption = sprintf(
            $this->t('en', 'payment_admin_caption'),
            Utils::h($name),
            $payment['user_telegram_id'],
            Utils::h($method['title_en']),
            Utils::fmtMoney($payment['amount']),
            $payment['receipt_type'] === 'photo' ? '[photo receipt]' : Utils::h($payment['receipt_value']),
            Utils::h($payment['note'])
        );
        $kb = array('inline_keyboard' => array(array(
            array('text' => $this->t('en', 'approve'), 'callback_data' => 'payapprove:' . $paymentId),
            array('text' => $this->t('en', 'reject'), 'callback_data' => 'payreject:' . $paymentId)
        ), array(
            array('text' => $this->t('en', 'edit_approve'), 'callback_data' => 'payeditapprove:' . $paymentId)
        )));
        foreach ($this->config['admin_ids'] as $adminId) {
            if ($payment['receipt_type'] === 'photo') {
                $this->api->sendPhoto($adminId, $payment['receipt_value'], Utils::safeText($caption, $this->config['security']['max_caption_length']), $kb);
            } else {
                $this->sendMessage($adminId, $caption, $kb);
            }
        }
    }

    protected function approvePayment($paymentId, $adminTelegramId, $overrideAmount = null, $isEdited = false)
    {
        $lock = $this->lock->acquire('payment:' . $paymentId);
        try {
            $payment = $this->payments->findById($paymentId);
            if (!$payment || $payment['status'] !== 'pending') {
                return;
            }
            $user = $this->users->findByTelegramId($payment['user_telegram_id']);
            if (!$user) {
                return;
            }
            if ($overrideAmount !== null) {
                $payment['amount'] = round((float)$overrideAmount, 2);
            }
            $user['credit'] = round(((float)$user['credit'] + (float)$payment['amount']), 2);
            $user['updated_at'] = Utils::now();
            $this->users->saveByTelegramId($user['telegram_id'], $user);
            $payment['status'] = 'approved';
            $payment['admin_note'] = ($isEdited ? 'Edited and approved by ' : 'Approved by ') . $adminTelegramId;
            $payment['updated_at'] = Utils::now();
            $this->payments->updateById($paymentId, $payment);
            $this->sendMessage($user['telegram_id'], sprintf($this->t($user['language'], 'payment_approved_user'), Utils::fmtMoney($payment['amount']), $this->runtimeCurrency(), Utils::fmtMoney($user['credit']), $this->runtimeCurrency()), null);
        } finally {
            $this->lock->release($lock);
        }
    }

    protected function rejectPayment($paymentId, $adminTelegramId, $reason)
    {
        $payment = $this->payments->findById($paymentId);
        if (!$payment || $payment['status'] !== 'pending') {
            return;
        }
        $payment['status'] = 'rejected';
        $payment['admin_note'] = $reason . ' by ' . $adminTelegramId;
        $payment['updated_at'] = Utils::now();
        $this->payments->updateById($paymentId, $payment);
        $user = $this->users->findByTelegramId($payment['user_telegram_id']);
        if ($user) {
            $this->sendMessage($user['telegram_id'], sprintf($this->t($user['language'], 'payment_rejected_user'), Utils::h($reason)), null);
        }
    }

    protected function showAdminPanel($chatId, $lang)
    {
        $salesText = $this->sellEnabled() ? $this->t($lang, 'admin_disable_sales') : $this->t($lang, 'admin_enable_sales');
        $kb = array('inline_keyboard' => array(
            array(array('text' => $this->t($lang, 'admin_users'), 'callback_data' => 'admin:users'), array('text' => $this->t($lang, 'admin_user_search'), 'callback_data' => 'admin:usersearch')),
            array(array('text' => $this->t($lang, 'admin_add_credit'), 'callback_data' => 'admin:add_credit'), array('text' => $this->t($lang, 'admin_broadcast'), 'callback_data' => 'admin:broadcast')),
            array(array('text' => $salesText, 'callback_data' => 'admin:sales_toggle'), array('text' => $this->t($lang, 'admin_settings'), 'callback_data' => 'admin:settings')),
            array(array('text' => $this->t($lang, 'admin_add_category'), 'callback_data' => 'admin:add_category'), array('text' => $this->t($lang, 'admin_list_categories'), 'callback_data' => 'admin:list_categories')),
            array(array('text' => $this->t($lang, 'admin_add_method'), 'callback_data' => 'admin:add_method'), array('text' => $this->t($lang, 'admin_methods_manage'), 'callback_data' => 'admin:methods')),
            array(array('text' => $this->t($lang, 'admin_pending_payments'), 'callback_data' => 'admin:pending_payments')),
            array(array('text' => $this->t($lang, 'admin_set_banner'), 'callback_data' => 'admin:set_banner')),
        ));
        $summary = sprintf($this->t($lang, 'admin_panel_summary'), count($this->users->all()), count($this->cats->visible()), count($this->payments->pending()), $this->runtimeCurrency(), $this->sellEnabled() ? $this->t($lang, 'status_enabled') : $this->t($lang, 'status_disabled'));
        $this->sendMessage($chatId, $this->t($lang, 'admin_panel') . "\n\n" . $summary, $kb);
    }

    protected function showAdminCategories($chatId, $lang)
    {
        $buttons = array();
        foreach ($this->cats->visible() as $cat) {
            $title = $lang === 'fa' ? $cat['title_fa'] : $cat['title_en'];
            $stats = $this->categoryStats($cat['id']);
            $buttons[] = array(array('text' => $title . ' | ' . $this->t($lang, 'qty_short') . ':' . $stats['available'] . ' | ' . $this->t($lang, 'sold_short') . ':' . $stats['sold'], 'callback_data' => 'admin:catmanage:' . $cat['id']));
        }
        if (!$buttons) {
            $this->sendMessage($chatId, $this->t($lang, 'no_categories'), null);
            return;
        }
        $this->sendMessage($chatId, $this->t($lang, 'choose_category_manage'), array('inline_keyboard' => $buttons));
    }

    protected function showAdminCategoryManage($chatId, $categoryId, $lang)
    {
        $cat = $this->cats->findById($categoryId);
        if (!$cat) {
            return;
        }
        $title = $lang === 'fa' ? $cat['title_fa'] : $cat['title_en'];
        $stats = $this->categoryStats($categoryId);
        $kb = array('inline_keyboard' => array(
            array(array('text' => $this->t($lang, 'add_stock'), 'callback_data' => 'admin:cataddstock:' . $categoryId), array('text' => $this->t($lang, 'rename_category'), 'callback_data' => 'admin:catedit:' . $categoryId)),
            array(array('text' => $this->t($lang, 'delete_category'), 'callback_data' => 'admin:catdelete:' . $categoryId))
        ));
        $text = sprintf($this->t($lang, 'category_manage'), Utils::h($title)) . "

" . sprintf($this->t($lang, 'category_manage_stats'), $stats['available'], $stats['sold'], $stats['archived'], $stats['total']);
        $this->sendMessage($chatId, $text, $kb);
    }

    protected function deleteCategory($chatId, $categoryId, $lang)
    {
        $cat = $this->cats->findById($categoryId);
        if (!$cat) {
            $this->sendMessage($chatId, $this->t($lang, 'purchase_failed'), null);
            return;
        }
        $cat['is_active'] = 0;
        $cat['is_deleted'] = 1;
        $cat['deleted_at'] = Utils::now();
        $cat['updated_at'] = Utils::now();
        $this->cats->updateById($categoryId, $cat);
        foreach ($this->items->all() as $item) {
            if ((string)$item['category_id'] === (string)$categoryId && $item['status'] === 'available') {
                $item['status'] = 'archived';
                $this->items->updateById($item['id'], $item);
            }
        }
        $this->sendMessage($chatId, $this->t($lang, 'category_deleted_preserved'), null);
    }

    protected function showPendingPayments($chatId, $lang)
    {
        $pending = $this->payments->pending();
        if (!$pending) {
            $this->sendMessage($chatId, $this->t($lang, 'no_pending_payments'), null);
            return;
        }
        $payment = $pending[0];
        $user = $this->users->findByTelegramId($payment['user_telegram_id']);
        $method = $this->methods->findById($payment['method_id']);
        $name = trim($user['first_name'] . ' (@' . $user['username'] . ')');
        $receiptText = $payment['receipt_type'] === 'photo' ? '[photo receipt]' : $payment['receipt_value'];
        $caption = sprintf($this->t($lang, 'payment_admin_caption'), Utils::h($name), $payment['user_telegram_id'], Utils::h($lang === 'fa' ? $method['title_fa'] : $method['title_en']), Utils::fmtMoney($payment['amount']), Utils::h($receiptText), Utils::h($payment['note']));
        $kb = array('inline_keyboard' => array(
            array(array('text' => $this->t($lang, 'approve'), 'callback_data' => 'payapprove:' . $payment['id']), array('text' => $this->t($lang, 'reject'), 'callback_data' => 'payreject:' . $payment['id'])),
            array(array('text' => $this->t($lang, 'edit_approve'), 'callback_data' => 'payeditapprove:' . $payment['id']))
        ));
        if ($payment['receipt_type'] === 'photo') {
            $this->api->sendPhoto($chatId, $payment['receipt_value'], Utils::safeText($caption, $this->config['security']['max_caption_length']), $kb);
        } else {
            $this->sendMessage($chatId, $caption, $kb);
        }
    }

    protected function showAdminPaymentMethods($chatId, $lang)
    {
        $methods = method_exists($this->methods, 'visible') ? $this->methods->visible() : $this->methods->all();
        if (!$methods) {
            $this->sendMessage($chatId, $this->t($lang, 'payment_methods_empty'), null);
            return;
        }
        $buttons = array();
        foreach ($methods as $method) {
            if (!empty($method['is_deleted'])) {
                continue;
            }
            $title = $lang === 'fa' ? $method['title_fa'] : $method['title_en'];
            $state = !empty($method['is_active']) ? $this->t($lang, 'status_enabled') : $this->t($lang, 'status_disabled');
            $buttons[] = array(array('text' => $title . ' | ' . $state, 'callback_data' => 'admin:method:' . $method['id']));
        }
        if (!$buttons) {
            $this->sendMessage($chatId, $this->t($lang, 'payment_methods_empty'), null);
            return;
        }
        $buttons[] = array(array('text' => $this->t($lang, 'admin_add_method'), 'callback_data' => 'admin:add_method'), array('text' => $this->t($lang, 'admin_panel'), 'callback_data' => 'admin:panel'));
        $this->sendMessage($chatId, $this->t($lang, 'choose_method_manage'), array('inline_keyboard' => $buttons));
    }

    protected function showAdminPaymentMethodManage($chatId, $lang, $methodId)
    {
        $method = $this->methods->findById($methodId);
        if (!$method || !empty($method['is_deleted'])) {
            $this->sendMessage($chatId, $this->t($lang, 'method_not_found'), null);
            return;
        }
        $title = $lang === 'fa' ? $method['title_fa'] : $method['title_en'];
        $details = $lang === 'fa' ? $method['details_fa'] : $method['details_en'];
        $state = !empty($method['is_active']) ? $this->t($lang, 'status_enabled') : $this->t($lang, 'status_disabled');
        $text = sprintf($this->t($lang, 'method_manage'), Utils::h($title), $state, Utils::h($details));
        $toggleText = !empty($method['is_active']) ? $this->t($lang, 'disable_method') : $this->t($lang, 'enable_method');
        $kb = array('inline_keyboard' => array(
            array(array('text' => $this->t($lang, 'edit_method'), 'callback_data' => 'admin:methodedit:' . $methodId), array('text' => $toggleText, 'callback_data' => 'admin:methodtoggle:' . $methodId)),
            array(array('text' => $this->t($lang, 'delete_method'), 'callback_data' => 'admin:methoddelete:' . $methodId)),
            array(array('text' => $this->t($lang, 'admin_methods_manage'), 'callback_data' => 'admin:methods'))
        ));
        $this->sendMessage($chatId, $text, $kb);
    }

    protected function deletePaymentMethod($chatId, $lang, $methodId)
    {
        $method = $this->methods->findById($methodId);
        if (!$method) {
            $this->sendMessage($chatId, $this->t($lang, 'method_not_found'), null);
            return;
        }
        $method['is_active'] = 0;
        $method['is_deleted'] = 1;
        $method['deleted_at'] = Utils::now();
        $method['updated_at'] = Utils::now();
        $this->methods->updateById($methodId, $method);
        $this->sendMessage($chatId, $this->t($lang, 'method_deleted'), null);
    }

    protected function togglePaymentMethod($chatId, $lang, $methodId)
    {
        $method = $this->methods->findById($methodId);
        if (!$method || !empty($method['is_deleted'])) {
            $this->sendMessage($chatId, $this->t($lang, 'method_not_found'), null);
            return;
        }
        $method['is_active'] = !empty($method['is_active']) ? 0 : 1;
        $method['updated_at'] = Utils::now();
        $this->methods->updateById($methodId, $method);
        $this->sendMessage($chatId, !empty($method['is_active']) ? $this->t($lang, 'method_enabled') : $this->t($lang, 'method_disabled'), null);
    }

    protected function orderCategoryTitle(array $order, $lang)
    {
        $cat = $this->cats->findById($order['category_id']);
        if ($cat) {
            return $lang === 'fa' ? $cat['title_fa'] : $cat['title_en'];
        }
        if ($lang === 'fa' && !empty($order['category_title_fa'])) {
            return $order['category_title_fa'];
        }
        if ($lang !== 'fa' && !empty($order['category_title_en'])) {
            return $order['category_title_en'];
        }
        if (!empty($order['category_title_en'])) {
            return $order['category_title_en'];
        }
        if (!empty($order['category_title_fa'])) {
            return $order['category_title_fa'];
        }
        return '#' . $order['category_id'];
    }

    protected function orderItemContent(array $order)
    {
        $item = $this->items->findById($order['item_id']);
        if ($item && isset($item['content']) && trim((string)$item['content']) !== '') {
            return $item['content'];
        }
        return isset($order['item_content']) ? $order['item_content'] : '';
    }

    protected function categoryStats($categoryId)
    {
        $rows = $this->items->byCategory($categoryId);
        $stats = array('available' => 0, 'sold' => 0, 'archived' => 0, 'total' => count($rows));
        foreach ($rows as $row) {
            if ($row['status'] === 'available') {
                $stats['available']++;
            } elseif ($row['status'] === 'sold') {
                $stats['sold']++;
            } else {
                $stats['archived']++;
            }
        }
        return $stats;
    }

    protected function lowStockThreshold()
    {
        $value = (int)$this->getRuntimeFloat('low_stock_threshold', 2);
        return $value > 0 ? $value : 2;
    }

    protected function lowStockFlagKey($categoryId)
    {
        return 'low_stock_notified_' . (int)$categoryId;
    }

    protected function refreshLowStockAlertState($categoryId)
    {
        $stats = $this->categoryStats($categoryId);
        if ($stats['available'] >= $this->lowStockThreshold()) {
            $this->settings->set($this->lowStockFlagKey($categoryId), 0);
        }
    }

    protected function maybeNotifyLowStock($categoryId)
    {
        $cat = $this->cats->findById($categoryId);
        if (!$cat) {
            return;
        }
        $stats = $this->categoryStats($categoryId);
        if ($stats['available'] >= $this->lowStockThreshold()) {
            $this->settings->set($this->lowStockFlagKey($categoryId), 0);
            return;
        }
        if ($this->getRuntimeBool($this->lowStockFlagKey($categoryId), false)) {
            return;
        }
        $this->settings->set($this->lowStockFlagKey($categoryId), 1);
        foreach ($this->config['admin_ids'] as $adminId) {
            $title = !empty($cat['title_en']) ? $cat['title_en'] : ('#' . $categoryId);
            $text = sprintf($this->t('en', 'low_stock_alert'), Utils::h($title), $stats['available'], $stats['sold']);
            $this->sendMessage($adminId, $text, null);
        }
    }

    protected function getRuntimeText($key, $default)
    {
        $value = $this->settings->get($key, $default);
        return is_string($value) ? $value : $default;
    }

    protected function getRuntimeTextFilled($key, $default)
    {
        $value = $this->settings->get($key, null);
        if (is_string($value) && trim($value) !== '') {
            return $value;
        }
        return $default;
    }

    protected function getRuntimeFloat($key, $default)
    {
        $value = $this->settings->get($key, $default);
        return is_numeric($value) ? (float)$value : (float)$default;
    }

    protected function getRuntimeBool($key, $default)
    {
        $value = $this->settings->get($key, $default ? 1 : 0);
        if (is_bool($value)) {
            return $value;
        }
        if (is_numeric($value)) {
            return ((int)$value) === 1;
        }
        $value = strtolower(trim((string)$value));
        return in_array($value, array('1', 'true', 'yes', 'on'), true);
    }

    protected function runtimeCurrency()
    {
        return $this->getRuntimeText('currency', isset($this->config['bot']['currency']) ? $this->config['bot']['currency'] : 'USD');
    }

    protected function paymentMin()
    {
        return $this->getRuntimeFloat('payment_amount_min', isset($this->config['security']['payment_amount_min']) ? $this->config['security']['payment_amount_min'] : 0.10);
    }

    protected function paymentMax()
    {
        return $this->getRuntimeFloat('payment_amount_max', isset($this->config['security']['payment_amount_max']) ? $this->config['security']['payment_amount_max'] : 1000000);
    }

    protected function isMaintenanceMode()
    {
        return $this->getRuntimeBool('maintenance_mode', !empty($this->config['bot']['maintenance_mode']));
    }

    protected function sellEnabled()
    {
        return $this->getRuntimeBool('sell_enabled', true);
    }

    protected function salesClosedText($lang)
    {
        return $lang === 'fa'
            ? $this->getRuntimeText('sales_closed_text_fa', 'فروش موقتاً بسته است. لطفاً تا باز شدن فروش منتظر بمانید.')
            : $this->getRuntimeText('sales_closed_text_en', 'Sales are temporarily closed. Please wait until sales reopen.');
    }

    protected function userDisplayName(array $user)
    {
        $name = trim((string)$user['first_name'] . ' ' . (string)$user['last_name']);
        if ($name === '') {
            $name = 'User';
        }
        if (!empty($user['username'])) {
            $name .= ' (@' . $user['username'] . ')';
        }
        return $name;
    }

    protected function sortedUsers()
    {
        $rows = $this->users->all();
        usort($rows, function ($a, $b) {
            return (int)$a['id'] < (int)$b['id'] ? 1 : -1;
        });
        return $rows;
    }

    protected function showAdminUsersPage($chatId, $lang, $page)
    {
        $users = $this->sortedUsers();
        if (!$users) {
            $this->sendMessage($chatId, $this->t($lang, 'admin_users_empty'), null);
            return;
        }
        $perPage = 10;
        $totalPages = max(1, (int)ceil(count($users) / $perPage));
        $page = max(1, min($totalPages, (int)$page));
        $slice = array_slice($users, ($page - 1) * $perPage, $perPage);
        $buttons = array();
        foreach ($slice as $u) {
            $buttons[] = array(array(
                'text' => '#' . $u['telegram_id'] . ' | ' . $this->userDisplayName($u),
                'callback_data' => 'admin:user:' . $u['telegram_id']
            ));
        }
        $nav = array();
        if ($page > 1) {
            $nav[] = array('text' => '◀', 'callback_data' => 'admin:userpage:' . ($page - 1));
        }
        $nav[] = array('text' => $page . '/' . $totalPages, 'callback_data' => 'admin:userpage:' . $page);
        if ($page < $totalPages) {
            $nav[] = array('text' => '▶', 'callback_data' => 'admin:userpage:' . ($page + 1));
        }
        $buttons[] = $nav;
        $buttons[] = array(array('text' => $this->t($lang, 'admin_user_search'), 'callback_data' => 'admin:usersearch'), array('text' => $this->t($lang, 'admin_panel'), 'callback_data' => 'admin:panel'));
        $this->sendMessage($chatId, sprintf($this->t($lang, 'admin_users_page_title'), $page, $totalPages, count($users)), array('inline_keyboard' => $buttons));
    }

    protected function showAdminUserSearchResults($chatId, $lang, $query)
    {
        $query = trim((string)$query);
        if ($query === '') {
            $this->sendMessage($chatId, $this->t($lang, 'admin_user_search_prompt'), null);
            return;
        }
        $users = $this->sortedUsers();
        $matched = array();
        foreach ($users as $u) {
            $hay = strtolower($u['telegram_id'] . ' ' . $u['username'] . ' ' . $u['first_name'] . ' ' . $u['last_name']);
            if (strpos($hay, strtolower($query)) !== false) {
                $matched[] = $u;
            }
        }
        if (!$matched) {
            $this->sendMessage($chatId, $this->t($lang, 'user_not_found'), null);
            return;
        }
        $matched = array_slice($matched, 0, 20);
        $buttons = array();
        foreach ($matched as $u) {
            $buttons[] = array(array('text' => '#' . $u['telegram_id'] . ' | ' . $this->userDisplayName($u), 'callback_data' => 'admin:user:' . $u['telegram_id']));
        }
        $buttons[] = array(array('text' => $this->t($lang, 'admin_users'), 'callback_data' => 'admin:users'));
        $this->sendMessage($chatId, sprintf($this->t($lang, 'admin_user_search_results'), Utils::h($query), count($matched)), array('inline_keyboard' => $buttons));
    }

    protected function showAdminUserDetail($chatId, $lang, $telegramId)
    {
        $user = $this->users->findByTelegramId($telegramId);
        if (!$user) {
            $this->sendMessage($chatId, $this->t($lang, 'user_not_found'), null);
            return;
        }
        $orders = $this->orders->byUser($telegramId);
        $status = !empty($user['is_banned']) ? $this->t($lang, 'status_banned') : $this->t($lang, 'status_active');
        $text = sprintf(
            $this->t($lang, 'admin_user_detail'),
            Utils::h($this->userDisplayName($user)),
            $user['telegram_id'],
            Utils::fmtMoney($user['credit']),
            $this->runtimeCurrency(),
            $status,
            count($orders),
            Utils::h($user['created_at'])
        );
        $buttons = array(
            array(
                array('text' => $this->t($lang, 'admin_add_credit'), 'callback_data' => 'admin:usercredit:' . $telegramId),
                array('text' => $this->t($lang, 'admin_view_accounts'), 'callback_data' => 'admin:useraccounts:' . $telegramId)
            ),
            array(
                array('text' => !empty($user['is_banned']) ? $this->t($lang, 'admin_unban_user') : $this->t($lang, 'admin_ban_user'), 'callback_data' => !empty($user['is_banned']) ? 'admin:userunban:' . $telegramId : 'admin:userban:' . $telegramId),
                array('text' => $this->t($lang, 'admin_users'), 'callback_data' => 'admin:users')
            )
        );
        $this->sendMessage($chatId, $text, array('inline_keyboard' => $buttons));
    }

    protected function adminBanUser($chatId, $lang, $targetId, $reason)
    {
        $user = $this->users->findByTelegramId($targetId);
        if (!$user) {
            $this->sendMessage($chatId, $this->t($lang, 'user_not_found'), null);
            return;
        }
        $reason = trim($reason);
        if ($reason === '') {
            $reason = 'No reason provided';
        }
        $user['is_banned'] = 1;
        $user['ban_reason'] = $reason;
        $user['updated_at'] = Utils::now();
        $this->users->saveByTelegramId($targetId, $user);
        $this->sendMessage($chatId, $this->t($lang, 'user_banned'), null);
        $this->sendMessage($targetId, sprintf($this->t($user['language'], 'banned'), Utils::h($reason)), null);
    }

    protected function adminUnbanUser($chatId, $lang, $targetId)
    {
        $user = $this->users->findByTelegramId($targetId);
        if (!$user) {
            $this->sendMessage($chatId, $this->t($lang, 'user_not_found'), null);
            return;
        }
        $user['is_banned'] = 0;
        $user['ban_reason'] = '';
        $user['updated_at'] = Utils::now();
        $this->users->saveByTelegramId($targetId, $user);
        $this->sendMessage($chatId, $this->t($lang, 'user_unbanned'), null);
    }

    protected function creditUserByAdmin($chatId, $lang, $targetId, $amount, $note, $adminTelegramId)
    {
        $user = $this->users->findByTelegramId($targetId);
        if (!$user) {
            $this->sendMessage($chatId, $this->t($lang, 'user_not_found'), null);
            return;
        }
        if ($amount <= 0) {
            $this->sendMessage($chatId, $this->t($lang, 'amount_invalid'), null);
            return;
        }
        $user['credit'] = round(((float)$user['credit'] + (float)$amount), 2);
        $user['updated_at'] = Utils::now();
        $this->users->saveByTelegramId($targetId, $user);
        $this->sendMessage($chatId, sprintf($this->t($lang, 'admin_credit_added'), Utils::fmtMoney($amount), $this->runtimeCurrency(), $targetId, Utils::fmtMoney($user['credit']), $this->runtimeCurrency()), null);
        $message = sprintf($this->t($user['language'], 'admin_credit_added_user'), Utils::fmtMoney($amount), $this->runtimeCurrency(), Utils::fmtMoney($user['credit']), $this->runtimeCurrency());
        if ($note !== '') {
            $message .= "\n\n" . sprintf($this->t($user['language'], 'admin_credit_note_line'), Utils::h($note));
        }
        $this->sendMessage($targetId, $message, null);
        $this->logger->info('Admin credit', array('admin' => $adminTelegramId, 'target' => $targetId, 'amount' => $amount));
    }

    protected function showAdminUserAccounts($chatId, $lang, $targetId)
    {
        $user = $this->users->findByTelegramId($targetId);
        if (!$user) {
            $this->sendMessage($chatId, $this->t($lang, 'user_not_found'), null);
            return;
        }
        $orders = $this->orders->byUser($targetId);
        if (!$orders) {
            $this->sendMessage($chatId, $this->t($lang, 'admin_user_accounts_empty'), null);
            return;
        }
        $parts = array();
        foreach ($orders as $order) {
            $cat = $this->cats->findById($order['category_id']);
            $item = $this->items->findById($order['item_id']);
            $title = $cat ? ($lang === 'fa' ? $cat['title_fa'] : $cat['title_en']) : ('#' . $order['category_id']);
            $content = $item ? trim($item['content']) : '';
            if ($content !== '') {
                $content = Utils::looksLikeLink($content) ? Utils::h($content) : '<code>' . Utils::h($content) . '</code>';
            }
            $parts[] = "#" . $order['id'] . " | " . Utils::h($title) . " | " . Utils::fmtMoney($order['price']) . ' ' . $this->runtimeCurrency() . "\n" . $content;
        }
        $text = sprintf($this->t($lang, 'admin_user_accounts_title'), Utils::h($this->userDisplayName($user)), count($orders)) . "\n\n" . implode("\n\n", $parts);
        $this->sendMessage($chatId, $text, array('inline_keyboard' => array(array(array('text' => $this->t($lang, 'admin_back_user'), 'callback_data' => 'admin:user:' . $targetId)))));
    }

    protected function showAdminBroadcastMenu($chatId, $lang)
    {
        $kb = array('inline_keyboard' => array(
            array(array('text' => $this->t($lang, 'announce_all'), 'callback_data' => 'admin:announce:all')),
            array(array('text' => $this->t($lang, 'announce_credit_users'), 'callback_data' => 'admin:announce:credit')),
            array(array('text' => $this->t($lang, 'announce_buyers'), 'callback_data' => 'admin:announce:buyers')),
            array(array('text' => $this->t($lang, 'admin_panel'), 'callback_data' => 'admin:panel'))
        ));
        $this->sendMessage($chatId, $this->t($lang, 'admin_broadcast_menu'), $kb);
    }

    protected function broadcastTargetLabel($target, $lang)
    {
        if ($target === 'credit') {
            return $this->t($lang, 'announce_credit_users');
        }
        if ($target === 'buyers') {
            return $this->t($lang, 'announce_buyers');
        }
        return $this->t($lang, 'announce_all');
    }

    protected function recipientsForTarget($target)
    {
        $users = $this->users->all();
        if ($target === 'all') {
            return $users;
        }
        if ($target === 'credit') {
            $rows = array();
            foreach ($users as $u) {
                if ((float)$u['credit'] > 0) {
                    $rows[] = $u;
                }
            }
            return $rows;
        }
        if ($target === 'buyers') {
            $buyerIds = array();
            foreach ($this->orders->all() as $order) {
                $buyerIds[(string)$order['user_telegram_id']] = true;
            }
            $rows = array();
            foreach ($users as $u) {
                if (isset($buyerIds[(string)$u['telegram_id']])) {
                    $rows[] = $u;
                }
            }
            return $rows;
        }
        return array();
    }

    protected function broadcastAnnouncement($chatId, $lang, $target, $message)
    {
        $message = trim((string)$message);
        if ($message === '') {
            $this->sendMessage($chatId, $this->t($lang, 'invalid_format'), null);
            return;
        }
        $users = $this->recipientsForTarget($target);
        $sent = 0;
        foreach ($users as $u) {
            $prefix = $this->t($u['language'], 'announcement_prefix');
            $this->sendMessage($u['telegram_id'], $prefix . "\n\n" . Utils::h($message), null);
            $sent++;
        }
        $this->sendMessage($chatId, sprintf($this->t($lang, 'announcement_sent'), $sent, Utils::h($this->broadcastTargetLabel($target, $lang))), null);
    }

    protected function showAdminSettingsMenu($chatId, $lang)
    {
        $summary = sprintf(
            $this->t($lang, 'admin_settings_summary'),
            Utils::h($this->runtimeCurrency()),
            Utils::fmtMoney($this->paymentMin()),
            Utils::fmtMoney($this->paymentMax()),
            $this->sellEnabled() ? $this->t($lang, 'status_enabled') : $this->t($lang, 'status_disabled'),
            $this->isMaintenanceMode() ? $this->t($lang, 'status_enabled') : $this->t($lang, 'status_disabled'),
            Utils::h($this->getRuntimeTextFilled('support_username', isset($this->config['bot']['support_username']) ? $this->config['bot']['support_username'] : '')),
            Utils::h($this->getRuntimeTextFilled('help_channel_url', isset($this->config['bot']['help_channel_url']) ? $this->config['bot']['help_channel_url'] : ''))
        ) . "
" . sprintf($this->t($lang, 'low_stock_threshold_line'), $this->lowStockThreshold());
        $kb = array('inline_keyboard' => array(
            array(array('text' => $this->t($lang, 'set_currency'), 'callback_data' => 'admin:setsingle:currency'), array('text' => $this->t($lang, 'set_support_username'), 'callback_data' => 'admin:setsingle:support_username')),
            array(array('text' => $this->t($lang, 'set_help_channel'), 'callback_data' => 'admin:setsingle:help_channel_url'), array('text' => $this->t($lang, 'set_payment_min'), 'callback_data' => 'admin:setsingle:payment_amount_min')),
            array(array('text' => $this->t($lang, 'set_payment_max'), 'callback_data' => 'admin:setsingle:payment_amount_max'), array('text' => $this->t($lang, 'set_low_stock_threshold'), 'callback_data' => 'admin:setsingle:low_stock_threshold')),
            array(array('text' => $this->t($lang, 'set_banner_pair'), 'callback_data' => 'admin:setpair:banner')),
            array(array('text' => $this->t($lang, 'set_help_pair'), 'callback_data' => 'admin:setpair:help'), array('text' => $this->t($lang, 'set_contact_pair'), 'callback_data' => 'admin:setpair:contact')),
            array(array('text' => $this->t($lang, 'set_sales_closed_pair'), 'callback_data' => 'admin:setpair:sales_closed_text'), array('text' => $this->t($lang, 'set_maintenance_pair'), 'callback_data' => 'admin:setpair:maintenance_text')),
            array(array('text' => $this->t($lang, 'toggle_maintenance'), 'callback_data' => 'admin:setbool:maintenance_mode'), array('text' => $this->t($lang, 'toggle_sell_setting'), 'callback_data' => 'admin:setbool:sell_enabled')),
            array(array('text' => $this->t($lang, 'admin_panel'), 'callback_data' => 'admin:panel'))
        ));
        $this->sendMessage($chatId, $this->t($lang, 'admin_settings') . "\n\n" . $summary, $kb);
    }

    protected function toggleSellEnabled($chatId, $lang)
    {
        $newValue = $this->sellEnabled() ? 0 : 1;
        $this->settings->set('sell_enabled', $newValue);
        $this->sendMessage($chatId, $newValue ? $this->t($lang, 'sales_enabled') : $this->t($lang, 'sales_disabled'), null);
    }

    protected function toggleRuntimeBooleanSetting($chatId, $lang, $key)
    {
        $current = $this->getRuntimeBool($key, false);
        $this->settings->set($key, $current ? 0 : 1);
        $this->sendMessage($chatId, sprintf($this->t($lang, 'setting_updated'), Utils::h($key)), null);
    }
}

