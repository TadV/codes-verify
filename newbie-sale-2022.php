<?php

class TM_Newbie_Sale_For_Promotion_22
{
    private $meta_key = 'order_with_newbie_sale';
    private $cancel_statuses = array("cancelled", "wc-cancelled", "trash", "wc-trash");
    private $remove_type = null;
    private $creation_order = false;

    public function __construct()
    {
        $this->init_hooks();
    }

    private function init_hooks()
    {
        // Проверка в корзине
        add_action('template_redirect', array( $this, 'check_gift_in_cart' ) );
        add_action('check_review_order_before_submit', array($this, 'check_gift_in_cart'));

        // Оформление обычного заказа
        add_action( 'woocommerce_checkout_update_order_meta', array( $this, 'create_normal_or_child_order' ), 1500 );

        // Оформление дочернего заказа
        add_action( 'child_order_created', array( $this, 'create_normal_or_child_order' ), 1500 );

        // Обновление дочернего заказа в ЛК
        add_action('lk_order_updated', array( $this,'check_gift_in_order' ), 1500 );
        add_action('lk_order_updated', array( $this,'time_modified' ), 9999 );

        // Отмена заказа
        add_action("woocommerce_order_status_cancelled", array( $this, 'cancelled_order' ), 10, 2 );
        add_action("wp_trash_post", array( $this, 'remove_gift_and_reserve_from_order' ), 10);

        // Оплата заказа
        add_action('woocommerce_pre_payment_complete', array( $this, 'order_payed'), 160, 1);

        // Текст в корзине

        global $auto_remove_gifts;
        $auto_remove_gifts = false;
        add_action( 'woocommerce_after_cart_table', array($this, 'add_gift_html_after_cart_content'));
        add_action( 'woocommerce_add_to_cart', array( $this, 'check_item_added' ), 10, 2 );
        add_action( 'woocommerce_cart_item_removed', array( $this, 'check_item_removed' ), 200, 2 );
        add_action( 'woocommerce_cart_item_restored', array( $this, 'check_item_restored' ), 200, 2 );

        date_default_timezone_set('Europe/Moscow');
        $now_datetime = date('Y-m-d H:i:s'); // 2020-06-23 15:00:00
        if ($now_datetime < '2022-08-16 00:00:00') {
            add_action('woocommerce_after_cart_table', array( $this, 'add_sale_html_after_cart_content' ), 1 );
        }

    }

    // Продукты в корзине/заказе? - Готово для одного
    private function is_products_in( $products_ids = array(), $order_id = null, $options = array('equal' => 'one') )
    {
        if (!is_array($products_ids)) $products_ids = array($products_ids);

        if (isset($order_id)) {
            $order = new TM_Order($order_id);
            $items = $order->get_items();

            if (count($items)) {
                foreach ($items as $item) {
                    $_product_id = $item->get_product_id();

                    if ( in_array($_product_id,$products_ids) ) {
                        return true;
                    }
                }
            }

            return false;
        }

        if (sizeof(WC()->cart->get_cart()) > 0) {
            foreach (WC()->cart->get_cart() as $cart_item_key => $values) {
                $_product = $values['product_id'];
                $_product = $values['data'];
                $_product_id = $_product->get_id();
                if (in_array($_product_id,$products_ids)) {
                    return true;
                }
            }
        }

        return false;
    }

    // Удаляем продукты из корзины/заказа
    private function remove_products($products_ids, $order_id = null)
    {
        $recalculate = false;
        if (isset($order_id) && isset($products_ids)) {
            $order = new TM_Order($order_id);
            $items = $order->get_items();
            foreach ($products_ids as $product_id) {
                if (count($items)) {
                    foreach ($items as $item) {
                        $item_id = $item->get_product_id();
                        if ($item_id == $product_id) {
                            $order->remove_item($item->get_id());
                            tentorium()->log('remove_gifts')->add($order_id,$item);
                            $recalculate = true;
                        }
                    }
                }
            }
            if ($recalculate) $this->total_calculate($order);
            return $recalculate;
        }

        if ( sizeof(WC()->cart->get_cart() ) > 0 ) {
            foreach (WC()->cart->get_cart() as $cart_item_key => $values) {
                $_product = $values['product_id'];
                if (in_array($_product,$products_ids,true)) {
                    $recalculate = true;
                    WC()->cart->remove_cart_item($cart_item_key);
                }
            }
        }

        return $recalculate;
    }

    public function get_gift_products_ids() {
        global $tm_promotions_rest;
        $res = $tm_promotions_rest->fn_gifts_list(["level" => 0]);
        $gift_products_ids = [];

        if (is_array($res)) {
            foreach ($res as $r) {
                foreach ($r["gifts_list"] as $gift) {
                    foreach ($gift["sku"] as $sku) {
                        $gift_ids = $this->get_product_id_by_characteristic($sku, true, true);
                        $gift_products_ids = $gift_ids ? array_merge($gift_products_ids, $gift_ids) : $gift_products_ids;
                    }
                }
            }
        }

        return array_unique($gift_products_ids);
    }

    private function check_gift($distributor_number, $total_points){
        global $tm_promotions_rest;
        $params = array(
            "distributor_number"=> $distributor_number,
            "service_center_id"=> "1002002000",
            "order_id" => "0",
            "order_points" => $total_points,
            "product_sku" => "0",
            "promotion_code" => "",
        );
        $res = $tm_promotions_rest->fn_check_gift($params);

        $list = [];
        if ($res) {
            foreach ($res as $promo_res) {
                if (isset($promo_res['check_data'][0]['gifts_statuses_list'])) {
                    foreach ($promo_res['check_data'][0]['gifts_statuses_list'] as $gift) {
                        if (!empty($gift)) $list[] = $gift;
                    }
                }
            }
        }

 //       if ( $distributor_number == 5044592) tentorium()->log('promotion_all')->add('check_gift',[$distributor_number,$list,$res]);
        if ( $distributor_number == 141) tentorium()->log('promotion_all')->add('check_gift',[$distributor_number,$list,$res]);
        return (!empty($list))?$list:false;
    }

    private function reserve_gift($distributor_number, $order_id, $total_points, $product_sku){
        global $tm_promotions_rest;
        $params = array(
            "distributor_number"=> $distributor_number,
            "service_center_id"=> "1002002000",
            "order_id" => $order_id,
            "order_points" => $total_points,
            "product_sku" => $product_sku,
            "promotion_code" => "",
        );
        return $tm_promotions_rest->fn_reserve_gift($params); // 1
    }

    private function use_gift($distributor_number, $order_id){
        global $tm_promotions_rest;
        $params = array(
            "distributor_number"=> $distributor_number,
            "service_center_id"=> "1002002000",
            "order_id" => $order_id,
            "promotion_code" => "",
        );
        return $tm_promotions_rest->fn_use_gift($params); // 1, 2
    }

    private function remove_gift($distributor_number, $order_id, $product_sku = null){
        global $tm_promotions_rest;
        $params = array(
            "distributor_number"=> $distributor_number,
            "service_center_id"=> "1002002000",
            "order_id" => $order_id,
            "product_sku" => $product_sku,
            "promotion_code" => "",
        );

        if ( $product_sku == null ) {
            $params["product_sku"] = 5017;
            $tm_promotions_rest->fn_remove_gift($params);

            $params["product_sku"] = 5161;
        }

        return $tm_promotions_rest->fn_remove_gift($params); // 1
    }

    private function get_and_remove_gift($distributor_number, $order_id) {
        $res = $this->check_gift($distributor_number,0);
        if (!$res) return;
        foreach ($res as $reserved_gift) {
            if ($reserved_gift['order_id'] == $order_id) {
                foreach ( $reserved_gift['sku'] as $sku) {
                    $this->remove_gift($distributor_number, $order_id, $sku);
                }
            }
        }
    }

    public function get_product_id_by_characteristic($product_characteristic, $get_all = false, $all_type = false) {
        if (is_array($product_characteristic)) $product_characteristic = implode(",", $product_characteristic);

        global $wpdb;

        if ( $all_type )
            $sql = "SELECT m.post_id as ID FROM ts_postmeta m LEFT JOIN ts_posts p ON p.ID = m.post_id WHERE 1=1 AND m.meta_key='_sync_id_for_orders_name' AND m.meta_value IN ( {$product_characteristic} )";
        else
            $sql = "SELECT m.post_id as ID FROM ts_postmeta m LEFT JOIN ts_posts p ON p.ID = m.post_id WHERE 1=1 AND m.meta_key='_sync_id_for_orders_name' AND m.meta_value IN ( {$product_characteristic} ) AND p.post_status = 'publish'";

        if (!$get_all) {
            $res = $wpdb->get_var($sql);
        }
        else {
            $r = $wpdb->get_results($sql, ARRAY_N);
            $res = [];
            foreach ($r as $value){
                $res[] = (int) $value[0];
            }
        }

        return $res?$res:false;
    }

    public function get_product_characteristic_by_id($product_id) {
        global $wpdb;
        $sql = "SELECT p.meta_value as sync_id FROM ts_postmeta p WHERE 1=1 AND p.meta_key='_sync_id_for_orders_name' AND p.post_id = {$product_id}";
        $res = $wpdb->get_var($sql);
        return $res?$res:false;
    }

    public function total_calculate($order) {

        /* @var TM_Order $order */

        $total = $order->_calculate_totals();
        $order->calculate_points();
        $res = $order->save();
        tentorium()->log('promotion_total_calculate')->add($order->get_id(), $total);

        if ($order->is_child_order()) {
            $group_order_id = $order->convert_to_child_order()->get_group_order_id();
            $group_order = new TM_Group_Order($group_order_id);
            $group_order->calculate_totals();
            $group_order->calculate_points();
            $res = $group_order->save();
            $total = $group_order->get_total();
            tentorium()->log('promotion_total_calculate')->add($group_order->get_id(), $total);
        }
    }

    /*
     * ------
     *  ХУКИ
     * ------
    */
    public function time_modified($order)
    {
        /* @var TM_Order $order */
        $order->set_date_modified(time());
        $order->save();
    }

    public function order_payed($order_id)
    {
        $order = new TM_Order($order_id);
        if ($order->is_autoorder()) {
            tentorium()->log('autoorder')->add($order_id, 'order_payed');
            $autoorder_parent_template_id = get_post_meta($order_id, 'autoorder_parent_template', true);

            $autoorder_template = new TM_Order($autoorder_parent_template_id);

            if ($autoorder_template->is_group_order()) {
                $childs_template = $autoorder_template->convert_to_group_order()->get_child_orders();
                foreach ($childs_template as $child_template_order) {
                    $child_order_id = $child_template_order->get_id();
                    $distributor_number = get_real_customer_login_from_order($child_order_id);
                    $this->get_and_remove_gift($distributor_number, $child_order_id);
                }
            } else {
                $distributor_number = get_real_customer_login_from_order($order_id);
                $this->get_and_remove_gift($distributor_number, $autoorder_parent_template_id);
            }

            if ($order->is_group_order()) {
                $childs = $order->convert_to_group_order()->get_child_orders();
                foreach ($childs as $child_order) {
                    $child_order_id = $child_order->get_id();
                    $autoorder_parent_template_id = get_post_meta($child_order_id, 'autoorder_parent_template', true);
                    $distributor_number = get_real_customer_login_from_order($child_order_id);
                    $total_points = TM_Points::calculate_order($child_order_id);

                    global $tm_promotions_rest;
                    $params = array(
                       // "distributor_number"=> $distributor_number,
                       // "service_center_id"=> "1002002000",
                       // "order_id" => "0",
                       // "order_points" => $total_points,
                       // "product_sku" => "0",
                       // "promotion_code" => "",
                    );

                    $res1 = $tm_promotions_rest->fn_check_gift(); //$params

                    $list1 = [];
                    if ($res1) {
                        foreach ($res1 as $promo_res1) {
                            if (isset($promo_res1['gifts_list'])) {
                                foreach ($promo_res1['sku'] as $gift1) {
                                    if (!empty($gift1)) $list1[] = $gift1;
                                }
                            }
                        }
                    }

                    // ПВН.
                    tentorium()->log('a_autoorder')->add($order_id, ['gifts_list',$list1]);

                    $gift_ids = $this->get_gift_products_ids();
                    foreach ($gift_ids as $gift_id){
                        if ($gift_id && $this->is_products_in([$gift_id],$child_order_id)) {
                            $sku = $this->get_product_characteristic_by_id($gift_id);
                            $this->reserve_gift($distributor_number, $child_order_id, $total_points, $sku);
                            $this->remove_products([$gift_id],$autoorder_parent_template_id);
                        }
                    }

                    $this->use_gift($distributor_number, $child_order_id);

                    if ($this->is_products_in($this->get_gift_products_ids(),$child_order_id)) {
                        $message = "Заказ с промоушном! Автозаказ!";
                        wp_mail('gulevich.v@tentorium.ru',$message,$child_order_id);
                    }
                }
            } else {
                $distributor_number = get_real_customer_login_from_order($order_id);
                $total_points = TM_Points::calculate_order($order_id);

                $gift_ids = $this->get_gift_products_ids();
                foreach ($gift_ids as $gift_id){
                    if ($gift_id && $this->is_products_in([$gift_id], $order_id)) {
                        $sku = $this->get_product_characteristic_by_id($gift_id);
                        $this->reserve_gift($distributor_number, $order_id, $total_points, $sku);
                        $this->remove_products([$gift_id],$autoorder_parent_template_id);
                    }
                }
                $this->use_gift($distributor_number, $order_id);
            }
        } else {
            if ($order->is_group_order()) {
                $childs = $order->convert_to_group_order()->get_child_orders();
                foreach ($childs as $child_order) {
                    $child_order_id = $child_order->get_id();
                    $distributor_number = get_real_customer_login_from_order($child_order_id);
                    $this->use_gift($distributor_number, $child_order_id);
                    if ($this->is_products_in($this->get_gift_products_ids(),$child_order_id)) {
                        $message = "Заказ с промоушном!";
                        wp_mail('gulevich.v@tentorium.ru',$message,$child_order_id);
                    }
                }
            } else {
                $distributor_number = get_real_customer_login_from_order($order_id);
                $this->use_gift($distributor_number, $order_id);
            }
        }

        if ($this->is_products_in($this->get_gift_products_ids(),$order_id)) {
            $message = "Заказ с промоушном!";
            if ($order->is_autoorder()) $message .= " Автозаказ!";
            wp_mail('gulevich.v@tentorium.ru',$message,$order_id);
        }
    }

    // Проверка в корзине - Готово
    public function check_gift_in_cart()
    {
        if ( ( is_checkout() && ! is_wc_endpoint_url() ) || is_cart() ) {
            global $auto_remove_gifts;
            $auto_remove_gifts = true;

            $gift_products_ids = $this->get_gift_products_ids();
            $gift_products_ids_added = [];
            $total_points = TM_Points::calculate_cart();

            global $current_user;

            if (isset($_GET['return_promotion'])) {
                delete_user_meta($current_user->ID,'skip_promotion');
                $referer = remove_query_arg( array( 'return_promotion' ), ( wp_get_referer() ? wp_get_referer() : wc_get_cart_url() ) );
                wp_safe_redirect( $referer );
                exit;
            }

            $logged_in = is_user_logged_in();
            $can_buy = array();

            $current_distr_number = $current_user->user_login;

            if (!$logged_in || admin_user()) { $current_distr_number = 9999999; }


            if (get_user_meta($current_user->ID,'skip_promotion', true)) {
                $this->remove_products($gift_products_ids);
                return true;
            }

            $can_buy = $this->check_gift($current_distr_number, $total_points);
            if ( $current_distr_number == 141)     tentorium()->log('aa_promotion_all')->add('all_list',[$current_distr_number,$can_buy]);


            if ( $can_buy && sizeof($can_buy) > 0 ) {
                foreach ($can_buy as $gift_product) {
                    if ( empty($gift_product) ) continue;
                    if ( $gift_product['status'] == "reserved" ) {
                        $payment_order = new TM_Order($gift_product['order_id']);
                        if ($payment_order->get_status() == "wc-pending") {
                            $payment_url = $payment_order->get_checkout_payment_url();
                            wc_add_notice("У вас есть не оплаченный заказ с зарезервированным товаром по промоушну «Выгодный&nbsp;старт»! <a href='{$payment_url}' target='_blank'>Сначала оплатите заказ: {$gift_product['order_id']}</a>", 'error');
                            global $block_order;
                            $block_order = $gift_product['order_id'];
                            tentorium()->log('promotion')->add('not-paid',$gift_product['order_id']);
                        } else {
                            tentorium()->log('promotion')->add('not-reserved',$gift_product['order_id']);
                        }
                    }
                    if ( $gift_product['status'] != "available" ) continue; // Подарок не доступен по статусу
                    if ( isset($gift_product['min_points']) ) {
//                        wc_add_notice( "Для получения возможности покупки акционных продуктов по промоушену необходимо набрать товаров более чем на {$gift_product['min_points']} баллов", 'notice' );
                        continue;
                    } // Подарок не доступен по баллам -> Нужно сделать уведомление

                    if ( !isset($gift_product["ID"]) ) $gift_product["ID"] = $this->get_product_id_by_characteristic($gift_product['sku']); // ID товара в ИМ

                    if ( isset($gift_product["ID"]) && $gift_product["ID"] ) {
                        if (!$this->is_products_in([$gift_product["ID"]])) {
                            $product = wc_get_product($gift_product["ID"]);
                            if (!$product) continue;
                            if ($product->get_status() != "publish") continue;
                            if ($product->get_stock_quantity() < 1) continue;
                            try {
                                // Доработка по количеству для промоушена Парад подарков 35 лет компании
                                $quantity = 1;
                                if ($gift_product['sku'] == '6107') {
                                    if ($total_points >= 1500) {
                                        $quantity = 1 +  floor(($total_points-1500)  / 500);
                                    }
                                }
                                if ( $current_distr_number == 141)    tentorium()->log('aa_promotion_all')->add('sku6',[$gift_product["ID"],$quantity]);
                                WC()->cart->add_to_cart($gift_product["ID"], $quantity);
                                $gift_products_ids_added[] = (int)$gift_product["ID"];
                            } catch (Exception $e) {
                            }
                        } else {
                            $gift_products_ids_added[] = (int)$gift_product["ID"];
                        }
                    }
                }
                $this->remove_products(array_diff($gift_products_ids, $gift_products_ids_added));
            }
            else {
                $this->remove_products($gift_products_ids);
            }
        }
    }

    // Проверка при создании обычного или дочернего заказа - Готово
    public function create_normal_or_child_order($order_id)
    {
        $order = new TM_Order($order_id);
        $this->creation_order = true;
        $this->check_gift_in_order($order);
    }

    // Проверка при обновлении заказа - Готово
    public function check_gift_in_order( $order ) {

        /* @var TM_Order $order */
        if (!$order->is_editable()){
            return;
        }

        $order_id = $order->get_id();
        $distributor_number = get_real_customer_login_from_order($order_id);
        $distributor_user = get_user_by('login',$distributor_number);
        $removed = $this->remove_products( $this->get_gift_products_ids(), $order_id );
        if ($removed) {
            $this->get_and_remove_gift($distributor_number, $order_id);
            $order = new TM_Order($order_id);
        }
        if (get_post_meta($order_id, 'remove_gift_promotion', true)) {
            $this->get_and_remove_gift($distributor_number, $order_id);
            delete_post_meta($order_id,'remove_gift_promotion');
        }

        if ($order->is_group_order()) {
            return;
        }

        if (    get_user_meta($distributor_user->ID,'skip_promotion', true)
            &&  $distributor_user->ID == $order->get_customer_user_id()
            &&  $this->creation_order) {
//                tentorium()->log('skip_promotion')->add('update',[$distributor_number,$_GET]);
                update_post_meta($order_id,'skip_promotion',1);
                return;
        }

        if (get_post_meta($order_id,'skip_promotion',true)) {
            return;
        }

        $total_points = TM_Points::calculate_order($order_id);
        if ( TM_Points::get_from_order($order_id) > $total_points ) $total_points = TM_Points::get_from_order($order_id);
        $can_buy = $this->check_gift($distributor_number, $total_points);

        tentorium()->log('promotion_all')->add($distributor_number,[$total_points, $can_buy]);
        if ( $can_buy ) {
            foreach ($can_buy as $gift_product) {
                if (empty($gift_product)) continue;
                if ( $gift_product['status'] != "available" ) continue; // Подарок не доступен по статусу
                if ( isset($gift_product['min_points']) ) continue; // Подарок не доступен по баллам -> Нужно сделать уведомление
                if ( !isset($gift_product["ID"]) ) $gift_product["ID"] = $this->get_product_id_by_characteristic($gift_product['sku']); // ID товара в ИМ
//                if ($gift_product["ID"] == 553319) tentorium()->log('TAD')->add('3steps',[$total_points,$order_id,$can_buy]);
                if ( !$gift_product["ID"] ) continue;

                if ($this->is_products_in([$gift_product["ID"]],$order_id)) continue;

                $product = wc_get_product($gift_product["ID"]);
                if ( !$product ) continue;
                if ( $product->get_status() != "publish") continue;
                if ( $product->get_stock_quantity() < 1) continue;
                $res = $this->reserve_gift($distributor_number, $order_id, $total_points, $gift_product['sku'][0]);
                if ( $res == 1 ) {
                    try {
                        $new_item_id = $order->add_product(
                            $product
                            , 1
                        );
                        tentorium()->log('promotion_add_product')->add($order_id, [$new_item_id, $product->get_title()]);
                    } catch (Exception $e) {}
                }
                tentorium()->log('promotion_all')->add('pre_reserve_gift',[$distributor_number, $order_id, $total_points, $gift_product['sku'], $res, $new_item_id]);

            }
            $this->total_calculate($order);
        }

    }

    public function add_promo($distr, $remove_meta) {
        if ($remove_meta) delete_user_meta($distr->ID,'promo_tri_added');
        if (get_user_meta($distr->ID,'promo_tri_added')) return false;
        if ($distr->user_login > '5000000' && $distr->user_registered > '2021-04-01 00:00:00') {
            return true;
        }
        return false;
    }

    // Отмена заказа - Готово
    public function cancelled_order($order_id, $context)
    {
        $this->remove_type = "cancelled";
        $this->remove_gift_and_reserve_from_order($order_id);
    }

    public function is_order($post_id) {
        global $wpdb;
        $sql__post_type = "SELECT post_type FROM ts_posts WHERE ID='{$post_id}'";
        $res = $wpdb->get_var($sql__post_type);
        return ($res == 'shop_order');
    }

    public function remove_gift_and_reserve_from_order($order_id) {
        if (!$this->is_order($order_id)) return;
        $this->remove_type = $this->remove_type??"wc-cancelled";
        tentorium()->log('change_status')->add($order_id,[ $this->remove_type, user() ]);
        $distributor_number = get_real_customer_login_from_order($order_id);
        $this->get_and_remove_gift($distributor_number,$order_id);

        $order = new TM_Order($order_id);
        if ($order->is_group_order()) {
            $childs = $order->convert_to_group_order()->get_child_orders();
            foreach ($childs as $child_order) {
                $order_id = $child_order->get_id();
                $distributor_number = get_real_customer_login_from_order($order_id);
                $this->get_and_remove_gift($distributor_number, $order_id);
                $child_order->set_status('wc-cancelled');
                $child_order->save();
            }
        }
    }

    public function add_sale_html_after_cart_content() {
        $points = TM_Points::calculate_cart();
        $mess = "Не упусти шанс и попади в 100-ку! Купи на 1500 баллов и участвуй в розыгрыше планшета, запаса ТОП-продуктов и других крутых призов.";
        if ( $points > 1500) {
            $points = $points - 1500;
            while ($points > 1000) { $points -= 1000; }
            $points = 1000 - $points;
            $mess = "Приз уже совсем близко! Купи еще на {$points} балла(ов) и получи дополнительный шанс выиграть планшет, запас ТОП-продуктов и других крутых призов.<br>Номера участника выдаются за каждые дополнительные 1000 баллов в чеке при покупке от 1500 баллов.";
        }
        ?>
        <div style="padding: 0.6em 1em;margin: 1em 0;border-radius: 0.5em 0 0 0.5em;background: #ebe9eb;color: black;max-width: 700px;float: right;border-right: 3px solid #8fae1b;"><?=$mess;?> <a href="https://tentorium.ru/pravila-provedeniya-marketingovoj-aktsii-rozygrysh-34-podarka-v-34-goda/" target="_pravila">Подробнее об акции.</a></div>
        <?php
    }

    public function add_gift_html_after_cart_content() {
        global $current_user;
        if (!get_user_meta($current_user->ID,'skip_promotion', true)) {
            return true;
        }
        //Выгодный&nbsp;старт
        ?>
        <div style="padding: 0.6em 1em;margin: 1em 0;border-radius: 0.5em 0 0 0.5em;background: #ebe9eb;color: black;max-width: 700px;max-width: min(100%,700px);float: right;border-right: 3px solid #8fae1b;"> Автоматическое добавление товаров по промоушену <a href="https://tentorium.ru/promotion2022/" target="_blank"></a> отключено.<br><a href="https://tentorium.ru/cart/?return_promotion=1" class="btn btn-success" style="margin: 0.5em 0;
    white-space: break-spaces;" target="_self">Включить автодобавление товаров</a></div>
        <?php

        return true;
    }

    public function check_item_removed($cart_item_key, $cart) {
        $product_id = $cart->removed_cart_contents[ $cart_item_key ]['product_id'];
        $gift_products_ids = $this->get_gift_products_ids();
        foreach ($gift_products_ids as $gift_products_id) {
            if ($gift_products_id == $product_id) {
                global $current_user, $auto_remove_gifts;
                if (!$auto_remove_gifts) update_user_meta($current_user->ID,'skip_promotion', 1);
            }
        }
    }

    public function check_item_restored($cart_item_key, $cart) {
        $product_id = $cart->removed_cart_contents[ $cart_item_key ]['product_id'];
        $gift_products_ids = $this->get_gift_products_ids();
        foreach ($gift_products_ids as $gift_products_id) {
            if ($gift_products_id == $product_id) {
                global $current_user;
                delete_user_meta($current_user->ID,'skip_promotion');
            }
        }
    }

    public function check_item_added($cart_item_key, $product_id) {
        $gift_products_ids = $this->get_gift_products_ids();
        foreach ($gift_products_ids as $gift_products_id) {
            if ($gift_products_id == $product_id) {
                global $current_user;
                delete_user_meta($current_user->ID,'skip_promotion');
            }
        }
    }
}

    global $newbie_sale_for_promotion;
    $newbie_sale_for_promotion = new TM_Newbie_Sale_For_Promotion_22();

// Получаем на кого оформлен заказ - Готово
function get_real_customer_login_from_order($order_id)
{
    $order          = new TM_Order( $order_id );
    $on_whom_login  = $order->get_on_whom_destination_distributor_number();
    if ( empty($on_whom_login) ) { 
        $customer_id    = $order->get_customer_id();
        $customer_user  = get_user_by( 'ID', $customer_id );
        $on_whom_login  = $customer_user->user_login;
    }

    return $on_whom_login;
}

