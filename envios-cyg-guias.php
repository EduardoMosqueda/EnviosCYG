<?php
/**
 * Plugin Name: Envios CYG - Guias de Transporte
 * Description: Gestiona clientes, guias de transporte y genera PDFs desde formularios frontend.
 * Version: 1.0.0
 * Author: Freshy
 * Text Domain: envios-cyg-guias
 */

if (!defined('ABSPATH')) {
    exit;
}

final class ECYG_Guias_Plugin
{
    const VERSION = '1.0.0';
    const CLIENT_CPT = 'ecyg_cliente';
    const GUIDE_CPT = 'ecyg_guia';
    const OPTION_KEY = 'ecyg_guias_options';
    const PAGE_OPTION = 'ecyg_guias_page_id';
    const SHORTCODE = 'envios_cyg_guias';

    private static $instance = null;

    public static function instance()
    {
        if (null === self::$instance) {
            self::$instance = new self();
        }

        return self::$instance;
    }

    private function __construct()
    {
        add_action('init', array($this, 'register_post_types'));
        add_action('init', array($this, 'handle_form_submissions'));
        add_action('wp_enqueue_scripts', array($this, 'register_frontend_assets'));
        add_shortcode(self::SHORTCODE, array($this, 'render_shortcode'));

        add_action('wp_ajax_ecyg_lookup_client', array($this, 'ajax_lookup_client'));

        add_action('admin_menu', array($this, 'register_settings_page'));
        add_action('admin_init', array($this, 'register_settings'));
        add_action('add_meta_boxes', array($this, 'register_meta_boxes'));
        add_action('save_post_' . self::CLIENT_CPT, array($this, 'save_client_meta_box'));

        add_filter('manage_' . self::CLIENT_CPT . '_posts_columns', array($this, 'client_columns'));
        add_action('manage_' . self::CLIENT_CPT . '_posts_custom_column', array($this, 'render_client_column'), 10, 2);
        add_filter('manage_' . self::GUIDE_CPT . '_posts_columns', array($this, 'guide_columns'));
        add_action('manage_' . self::GUIDE_CPT . '_posts_custom_column', array($this, 'render_guide_column'), 10, 2);
        add_action('wp_ajax_ecyg_get_report', array($this, 'ajax_get_report'));
        add_action('template_redirect', array($this, 'handle_pdf_report_download'));
        }

    public static function activate()
    {
        self::instance()->register_post_types();
        self::instance()->maybe_create_app_page();
        flush_rewrite_rules();
    }

    public static function deactivate()
    {
        flush_rewrite_rules();
    }

    public function register_post_types()
    {
        register_post_type(
            self::CLIENT_CPT,
            array(
                'labels' => array(
                    'name' => 'Clientes',
                    'singular_name' => 'Cliente',
                    'add_new_item' => 'Crear cliente',
                    'edit_item' => 'Editar cliente',
                    'new_item' => 'Nuevo cliente',
                    'view_item' => 'Ver cliente',
                    'search_items' => 'Buscar clientes',
                    'not_found' => 'No se encontraron clientes',
                    'menu_name' => 'Clientes',
                ),
                'public' => false,
                'show_ui' => true,
                'show_in_menu' => true,
                'menu_icon' => 'dashicons-id',
                'supports' => array('title'),
                'capability_type' => 'post',
                'map_meta_cap' => true,
                'rewrite' => false,
            )
        );

        register_post_type(
            self::GUIDE_CPT,
            array(
                'labels' => array(
                    'name' => 'Guias',
                    'singular_name' => 'Guia',
                    'add_new_item' => 'Crear guia',
                    'edit_item' => 'Editar guia',
                    'new_item' => 'Nueva guia',
                    'view_item' => 'Ver guia',
                    'search_items' => 'Buscar guias',
                    'not_found' => 'No se encontraron guias',
                    'menu_name' => 'Guias',
                ),
                'public' => false,
                'show_ui' => true,
                'show_in_menu' => true,
                'menu_icon' => 'dashicons-media-document',
                'supports' => array('title'),
                'capability_type' => 'post',
                'map_meta_cap' => true,
                'rewrite' => false,
            )
        );
    }

    public function maybe_create_app_page()
    {
        $page_id = absint(get_option(self::PAGE_OPTION));
        if ($page_id && get_post($page_id)) {
            return;
        }

        $existing = get_page_by_path('sistema-de-guias');
        if ($existing) {
            update_option(self::PAGE_OPTION, $existing->ID);
            return;
        }

        $page_id = wp_insert_post(
            array(
                'post_type' => 'page',
                'post_status' => 'publish',
                'post_title' => 'Sistema de Guias',
                'post_name' => 'sistema-de-guias',
                'post_content' => '[' . self::SHORTCODE . ']',
            )
        );

        if (!is_wp_error($page_id) && $page_id) {
            update_option(self::PAGE_OPTION, $page_id);
        }
    }

    public function register_frontend_assets()
    {
        wp_register_style(
            'ecyg-guias',
            plugins_url('assets/css/frontend.css', __FILE__),
            array(),
            self::VERSION
        );

        wp_register_script(
            'ecyg-guias',
            plugins_url('assets/js/frontend.js', __FILE__),
            array(),
            self::VERSION,
            true
        );

        wp_localize_script(
            'ecyg-guias',
            'ECYGGuias',
            array(
                'ajaxUrl' => admin_url('admin-ajax.php'),
                'lookupNonce' => wp_create_nonce('ecyg_lookup_client'),
                'messages' => array(
                    'searching' => 'Buscando cliente...',
                    'found' => 'Cliente encontrado. Los datos fueron cargados.',
                    'notFound' => 'Cliente no encontrado. Completa los datos para crearlo.',
                    'error' => 'No se pudo consultar el cliente.',
                ),
            )
        );
    }

    public function register_settings_page()
    {
        add_options_page(
            'Guias de transporte',
            'Guias de transporte',
            'manage_options',
            'ecyg-guias-settings',
            array($this, 'render_settings_page')
        );
    }

    public function register_settings()
    {
        register_setting(
            self::OPTION_KEY,
            self::OPTION_KEY,
            array(
                'sanitize_callback' => array($this, 'sanitize_options'),
                'default' => $this->default_options(),
            )
        );
    }

    public function default_options()
    {
        return array(
            'company_name' => 'Envios CYG, C.A.',
            'company_rif' => 'J-505780344',
            'company_address' => 'Calle 11, Vereda 45 Casa N 5, Urb Las Aguitas Sector 2, Los Guayos Edo. Carabobo',
            'company_contact' => '',
            'company_logo_url' => '',
            'guide_prefix' => 'G-',
            'next_number' => 1,
        );
    }

    public function get_options()
    {
        $options = get_option(self::OPTION_KEY, array());
        if (!is_array($options)) {
            $options = array();
        }

        return wp_parse_args($options, $this->default_options());
    }

    public function sanitize_options($input)
    {
        $input = is_array($input) ? $input : array();

        return array(
            'company_name' => sanitize_text_field($input['company_name'] ?? ''),
            'company_rif' => sanitize_text_field($input['company_rif'] ?? ''),
            'company_address' => sanitize_textarea_field($input['company_address'] ?? ''),
            'company_contact' => sanitize_text_field($input['company_contact'] ?? ''),
            'company_logo_url' => esc_url_raw($input['company_logo_url'] ?? ''),
            'guide_prefix' => sanitize_text_field($input['guide_prefix'] ?? 'G-'),
            'next_number' => max(1, absint($input['next_number'] ?? 1)),
        );
    }

    public function render_settings_page()
    {
        if (!current_user_can('manage_options')) {
            return;
        }

        $options = $this->get_options();
        ?>
        <div class="wrap">
            <h1>Guias de transporte</h1>
            <form method="post" action="options.php">
                <?php settings_fields(self::OPTION_KEY); ?>
                <table class="form-table" role="presentation">
                    <tr>
                        <th scope="row"><label for="ecyg_company_name">Nombre de la empresa</label></th>
                        <td><input class="regular-text" id="ecyg_company_name" name="<?php echo esc_attr(self::OPTION_KEY); ?>[company_name]" value="<?php echo esc_attr($options['company_name']); ?>" type="text"></td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="ecyg_company_rif">RIF</label></th>
                        <td><input class="regular-text" id="ecyg_company_rif" name="<?php echo esc_attr(self::OPTION_KEY); ?>[company_rif]" value="<?php echo esc_attr($options['company_rif']); ?>" type="text"></td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="ecyg_company_address">Direccion</label></th>
                        <td><textarea class="large-text" id="ecyg_company_address" name="<?php echo esc_attr(self::OPTION_KEY); ?>[company_address]" rows="3"><?php echo esc_textarea($options['company_address']); ?></textarea></td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="ecyg_company_contact">Contacto</label></th>
                        <td><input class="regular-text" id="ecyg_company_contact" name="<?php echo esc_attr(self::OPTION_KEY); ?>[company_contact]" value="<?php echo esc_attr($options['company_contact']); ?>" type="text"></td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="ecyg_company_logo_url">URL del logo</label></th>
                        <td>
                            <input class="regular-text" id="ecyg_company_logo_url" name="<?php echo esc_attr(self::OPTION_KEY); ?>[company_logo_url]" value="<?php echo esc_url($options['company_logo_url']); ?>" type="url">
                            <p class="description">Usa una imagen local de la biblioteca de medios. Los logos PNG se convierten a JPG para insertarlos en el PDF.</p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="ecyg_guide_prefix">Prefijo de guia</label></th>
                        <td><input id="ecyg_guide_prefix" name="<?php echo esc_attr(self::OPTION_KEY); ?>[guide_prefix]" value="<?php echo esc_attr($options['guide_prefix']); ?>" type="text"></td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="ecyg_next_number">Siguiente correlativo</label></th>
                        <td><input id="ecyg_next_number" name="<?php echo esc_attr(self::OPTION_KEY); ?>[next_number]" value="<?php echo esc_attr($options['next_number']); ?>" type="number" min="1"></td>
                    </tr>
                </table>
                <?php submit_button(); ?>
            </form>
        </div>
        <?php
    }

    public function render_shortcode()
    {
        if (!is_user_logged_in()) {
            return '<div class="ecyg-app"><p>Debes iniciar sesion para usar el sistema de guias.</p>' . wp_login_form(array('echo' => false)) . '</div>';
        }

        if (!current_user_can('edit_posts')) {
            return '<div class="ecyg-app"><p>No tienes permisos para gestionar guias.</p></div>';
        }

        wp_enqueue_style('ecyg-guias');
        wp_enqueue_script('ecyg-guias');

        $action = isset($_GET['ecyg_action']) ? sanitize_key(wp_unslash($_GET['ecyg_action'])) : '';

        ob_start();
        echo '<div class="ecyg-app">';
        $this->render_report_ui();
        $this->render_notice();

        if ('create-guide' === $action) {
            $this->render_create_guide_form();
        } elseif ('create-client' === $action) {
            $this->render_create_client_form();
        } else {
            $this->render_home();
        }

        echo '</div>';

        return ob_get_clean();
    }

    private function render_home()
    {
        $base_url = $this->get_app_url();
        ?>
        <div class="ecyg-header">
            <h2>Sistema de guias</h2>
        </div>
        <div class="ecyg-actions">
            <a class="ecyg-action-card" href="<?php echo esc_url(add_query_arg('ecyg_action', 'create-guide', $base_url)); ?>">
                <strong>Crear guia</strong>
                <span>Generar una guia y su PDF.</span>
            </a>
            <a class="ecyg-action-card" href="<?php echo esc_url(admin_url('edit.php?post_type=' . self::GUIDE_CPT)); ?>">
                <strong>Ver guias</strong>
                <span>Abrir el listado en WordPress.</span>
            </a>
            <a class="ecyg-action-card" href="<?php echo esc_url(add_query_arg('ecyg_action', 'create-client', $base_url)); ?>">
                <strong>Crear cliente</strong>
                <span>Registrar datos de un cliente.</span>
            </a>
            <a class="ecyg-action-card" href="<?php echo esc_url(admin_url('edit.php?post_type=' . self::CLIENT_CPT)); ?>">
                <strong>Ver clientes</strong>
                <span>Abrir el listado en WordPress.</span>
            </a>
        </div>
        <?php
    }

    private function render_notice()
    {
        $notice = isset($_GET['ecyg_notice']) ? sanitize_key(wp_unslash($_GET['ecyg_notice'])) : '';
        if (!$notice) {
            return;
        }

        $messages = array(
            'client_created' => 'Cliente creado correctamente.',
            'client_exists' => 'Ya existe un cliente con ese documento.',
            'client_error' => 'No se pudo guardar el cliente. Revisa los campos obligatorios.',
            'guide_created' => 'Guia creada correctamente.',
            'guide_created_pdf_error' => 'Guia creada, pero no se pudo generar el PDF. Revisa los permisos de la carpeta de uploads.',
            'guide_error' => 'No se pudo crear la guia. Revisa los campos obligatorios.',
        );

        if (!isset($messages[$notice])) {
            return;
        }

        $class = false !== strpos($notice, 'error') ? 'ecyg-notice error' : 'ecyg-notice';
        echo '<div class="' . esc_attr($class) . '">';
        echo '<p>' . esc_html($messages[$notice]) . '</p>';

        if (in_array($notice, array('guide_created', 'guide_created_pdf_error'), true) && !empty($_GET['ecyg_pdf'])) {
            $pdf_url = esc_url_raw(wp_unslash($_GET['ecyg_pdf']));
            echo '<a class="ecyg-button" target="_blank" rel="noopener" href="' . esc_url($pdf_url) . '">Abrir PDF</a>';
        }

        echo '</div>';
    }

    private function render_create_client_form()
    {
        $base_url = $this->get_app_url();
        ?>
        <div class="ecyg-header">
            <h2>Crear cliente</h2>
            <a href="<?php echo esc_url($base_url); ?>">Volver</a>
        </div>
        <form class="ecyg-form" method="post">
            <?php wp_nonce_field('ecyg_create_client', 'ecyg_nonce'); ?>
            <input type="hidden" name="ecyg_form_action" value="create_client">
            <input type="hidden" name="ecyg_return_url" value="<?php echo esc_url(add_query_arg('ecyg_action', 'create-client', $base_url)); ?>">
            <?php $this->render_client_fields(); ?>
            <div class="ecyg-form-actions">
                <button class="ecyg-button primary" type="submit">Guardar cliente</button>
            </div>
        </form>
        <?php
    }

    private function render_create_guide_form()
    {
        $base_url = $this->get_app_url();
        ?>
        <div class="ecyg-header">
            <h2>Crear guia</h2>
            <a href="<?php echo esc_url($base_url); ?>">Volver</a>
        </div>

        <form class="ecyg-form ecyg-guide-form" method="post" data-guide-form>
            <?php wp_nonce_field('ecyg_create_guide', 'ecyg_nonce'); ?>
            <input type="hidden" name="ecyg_form_action" value="create_guide">
            <input type="hidden" name="ecyg_return_url" value="<?php echo esc_url(add_query_arg('ecyg_action', 'create-guide', $base_url)); ?>">
            <input type="hidden" name="client_id" value="" data-client-id>

           <section class="ecyg-panel">
            <h3>Remitente</h3>
            <input type="hidden" name="sender_id" value="" data-client-id>
            <div class="ecyg-grid ecyg-grid-lookup">
                <label>
                    <span>Tipo de documento</span>
                    <?php $this->render_document_type_select('sender_lookup[document_type]', 'cedula', 'data-client-lookup-type'); ?>
                </label>
                <label>
                    <span>Número de documento</span>
                    <input type="text" name="sender_lookup[document_number]" data-client-lookup-number required>
                </label>
                <button class="ecyg-button" type="button" data-client-lookup>Buscar remitente</button>
            </div>
            <p class="ecyg-status" data-client-status></p>
            <div class="ecyg-client-fields" data-client-fields>
                <?php $this->render_client_fields(array(), 'sender'); ?>
            </div>
        </section>

        <section class="ecyg-panel">
            <h3>Destinatario</h3>
            <input type="hidden" name="client_id" value="" data-client-id>
            <div class="ecyg-grid ecyg-grid-lookup">
                <label>
                    <span>Tipo de documento</span>
                    <?php $this->render_document_type_select('client_lookup[document_type]', 'cedula', 'data-client-lookup-type'); ?>
                </label>
                <label>
                    <span>Número de documento</span>
                    <input type="text" name="client_lookup[document_number]" data-client-lookup-number required>
                </label>
                <button class="ecyg-button" type="button" data-client-lookup>Buscar destinatario</button>
            </div>
            <p class="ecyg-status" data-client-status></p>
            <div class="ecyg-client-fields" data-client-fields>
                <?php $this->render_client_fields(array(), 'client'); ?>
            </div>
        </section>

            <section class="ecyg-panel">
                <h3>Datos de la guia</h3>
                <div class="ecyg-grid">
                    <label>
                        <span>Fecha de emision</span>
                        <input type="date" name="issue_date" value="<?php echo esc_attr(current_time('Y-m-d')); ?>" required>
                    </label>
                </div>
            </section>
            <section class="ecyg-panel">
    <h3>Información de Facturación</h3>
    <div class="ecyg-grid">
        <label>
            <span>Tipo de Pago</span>
            <select name="payment_type" required style="width: 100%; padding: 8px; border: 1px solid #ddd; border-radius: 4px;">
                <option value="Pagado">Pagado</option>
                <option value="Destino">Destino</option>
                <option value="Crédito">Crédito</option>
            </select>
        </label>
    </div>
</section>

            <section class="ecyg-panel">
                <div class="ecyg-panel-heading">
                    <h3>Paquetes</h3>
                    <button class="ecyg-button" type="button" data-add-package>Agregar paquete</button>
                </div>
                <div class="ecyg-package-table" data-package-table>
                    <div class="ecyg-package-row ecyg-package-head">
                        <span>Cant.</span>
                        <span>Descripcion</span>
                        <span>Precio unit.</span>
                        <span>Total</span>
                        <span></span>
                    </div>
                    <?php $this->render_package_row(0); ?>
                </div>
                <div class="ecyg-total">
                    <span>Total general</span>
                    <strong data-grand-total>0,00</strong>
                </div>
            </section>

            <div class="ecyg-form-actions">
                <button class="ecyg-button primary" type="submit">Guardar y generar PDF</button>
            </div>
        </form>
        <?php
    }

   private function render_client_fields($values = array(), $prefix = 'client')
{
    $values = wp_parse_args(
        $values,
        array(
            'name' => '',
            'document_type' => 'cedula',
            'document_number' => '',
            'phone' => '',
            'state' => '',
            'city' => '',
            'address' => '',
        )
    );
    ?>
    <div class="ecyg-grid">
        <label>
            <span>Nombre y apellido</span>
            <input type="text" name="<?php echo esc_attr($prefix); ?>[name]" value="<?php echo esc_attr($values['name']); ?>" data-client-field="name" required>
        </label>
        <label>
            <span>Tipo de documento</span>
            <?php $this->render_document_type_select($prefix . '[document_type]', $values['document_type'], 'data-client-field="document_type"'); ?>
        </label>
        <label>
            <span>Número de documento</span>
            <input type="text" name="<?php echo esc_attr($prefix); ?>[document_number]" value="<?php echo esc_attr($values['document_number']); ?>" data-client-field="document_number" required>
        </label>
        <label>
            <span>Teléfono</span>
            <input type="tel" name="<?php echo esc_attr($prefix); ?>[phone]" value="<?php echo esc_attr($values['phone']); ?>" data-client-field="phone" required>
        </label>
        <label>
            <span>Estado</span>
            <input type="text" name="<?php echo esc_attr($prefix); ?>[state]" value="<?php echo esc_attr($values['state']); ?>" data-client-field="state" required>
        </label>
        <label>
            <span>Ciudad</span>
            <input type="text" name="<?php echo esc_attr($prefix); ?>[city]" value="<?php echo esc_attr($values['city']); ?>" data-client-field="city" required>
        </label>
        <label class="ecyg-span-2">
            <span>Dirección</span>
            <textarea name="<?php echo esc_attr($prefix); ?>[address]" rows="3" data-client-field="address" required><?php echo esc_textarea($values['address']); ?></textarea>
        </label>
    </div>
    <?php
}

    private function render_document_type_select($name, $selected = 'cedula', $attrs = '')
    {
        $types = $this->document_types();
        echo '<select name="' . esc_attr($name) . '" ' . $attrs . '>';
        foreach ($types as $value => $label) {
            echo '<option value="' . esc_attr($value) . '"' . selected($selected, $value, false) . '>' . esc_html($label) . '</option>';
        }
        echo '</select>';
    }

    private function render_package_row($index)
    {
        ?>
        <div class="ecyg-package-row" data-package-row>
            <input type="number" name="packages[<?php echo esc_attr($index); ?>][quantity]" min="1" step="1" value="1" data-package-quantity required>
            <input type="text" name="packages[<?php echo esc_attr($index); ?>][description]" data-package-description required>
            <input type="number" name="packages[<?php echo esc_attr($index); ?>][unit_price]" min="0" step="0.01" value="0.00" data-package-price required>
            <output data-package-total>0,00</output>
            <button class="ecyg-icon-button" type="button" data-remove-package aria-label="Eliminar paquete">x</button>
        </div>
        <?php
    }

    public function handle_form_submissions()
    {
        if (empty($_POST['ecyg_form_action'])) {
            return;
        }

        if (!is_user_logged_in() || !current_user_can('edit_posts')) {
            wp_die('No tienes permisos para realizar esta accion.');
        }

        $form_action = sanitize_key(wp_unslash($_POST['ecyg_form_action']));

        if ('create_client' === $form_action) {
            $this->handle_create_client();
        }

        if ('create_guide' === $form_action) {
            $this->handle_create_guide();
        }
    }

    private function handle_create_client()
    {
        if (!isset($_POST['ecyg_nonce']) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['ecyg_nonce'])), 'ecyg_create_client')) {
            wp_die('Nonce invalido.');
        }

        $return_url = $this->posted_return_url();
        $client = $this->sanitize_client_payload($_POST['client'] ?? array());

        if (!$this->is_valid_client_payload($client)) {
            $this->redirect_with_notice($return_url, 'client_error');
        }

        $existing_id = $this->find_client_by_document($client['document_number'], $client['document_type']);
        if ($existing_id) {
            $this->redirect_with_notice($return_url, 'client_exists');
        }

        $client_id = $this->save_client($client);
        $this->redirect_with_notice($return_url, $client_id ? 'client_created' : 'client_error');
    }

    private function handle_create_guide()
    {
        if (!isset($_POST['ecyg_nonce']) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['ecyg_nonce'])), 'ecyg_create_guide')) {
            wp_die('Nonce invalido.');
        }

        $return_url = $this->posted_return_url();
        $client_id = absint($_POST['client_id'] ?? 0);
        $client = $this->sanitize_client_payload($_POST['client'] ?? array());
        $issue_date = sanitize_text_field(wp_unslash($_POST['issue_date'] ?? ''));
        $packages = $this->sanitize_packages($_POST['packages'] ?? array());

        $sender_id = absint($_POST['sender_id'] ?? 0);
        $sender = $this->sanitize_client_payload($_POST['sender'] ?? array());

        // Guardar el tipo de pago asignado a la guía
        $payment_type = sanitize_text_field($_POST['payment_type'] ?? 'Pagado');


        

        if (!$issue_date || !$packages) {
            $this->redirect_with_notice($return_url, 'guide_error');
        }

        if ($client_id && self::CLIENT_CPT === get_post_type($client_id)) {
            $client = $this->get_client_snapshot($client_id);
        } else {
            if (!$this->is_valid_client_payload($client)) {
                $this->redirect_with_notice($return_url, 'guide_error');
            }

            $existing_id = $this->find_client_by_document($client['document_number'], $client['document_type']);
            $client_id = $existing_id ? $existing_id : $this->save_client($client);
            if (!$client_id) {
                $this->redirect_with_notice($return_url, 'guide_error');
            }
            $client = $this->get_client_snapshot($client_id);
        }

        // Lógica para registrar/buscar el remitente en el CPT ecyg_cliente
        if ($sender_id && self::CLIENT_CPT === get_post_type($sender_id)) {
          $sender = $this->get_client_snapshot($sender_id);
            } else {
        if (!$this->is_valid_client_payload($sender)) {
        $this->redirect_with_notice($return_url, 'guide_error');
            }
            $existing_sender = $this->find_client_by_document($sender['document_number'], $sender['document_type']);
            $sender_id = $existing_sender ? $existing_sender : $this->save_client($sender);
    
    if (!$sender_id) {
        $this->redirect_with_notice($return_url, 'guide_error');
    }
    $sender = $this->get_client_snapshot($sender_id);
}

        $options = $this->get_options();
        $sequence = max(1, absint($options['next_number']));
        $guide_number = $this->format_guide_number($sequence, $options['guide_prefix']);
        $total = array_sum(wp_list_pluck($packages, 'total'));

        $guide_id = wp_insert_post(
            array(
                'post_type' => self::GUIDE_CPT,
                'post_status' => 'publish',
                'post_title' => sprintf('Guia %s - %s', $guide_number, $client['name']),
            )
        );

        if (is_wp_error($guide_id) || !$guide_id) {
            $this->redirect_with_notice($return_url, 'guide_error');
        }

        update_post_meta($guide_id, '_ecyg_guide_number', $guide_number);
        update_post_meta($guide_id, '_ecyg_issue_date', $issue_date);
        update_post_meta($guide_id, '_ecyg_client_id', $client_id);
        update_post_meta($guide_id, '_ecyg_client_snapshot', $client);
        update_post_meta($guide_id, '_ecyg_packages', $packages);
        update_post_meta($guide_id, '_ecyg_total', $total);
        update_post_meta($guide_id, '_ecyg_sender_id', $sender_id);
        update_post_meta($guide_id, '_ecyg_sender_snapshot', $sender);
        update_post_meta($guide_id, '_ecyg_payment_type', $payment_type);

        $options['next_number'] = $sequence + 1;
        update_option(self::OPTION_KEY, $options);

        $notice = 'guide_created';
        $pdf = $this->generate_guide_pdf($guide_id);
        if ($pdf && !empty($pdf['url'])) {
            update_post_meta($guide_id, '_ecyg_pdf_url', $pdf['url']);
            update_post_meta($guide_id, '_ecyg_pdf_path', $pdf['path']);
            $return_url = add_query_arg('ecyg_pdf', $pdf['url'], $return_url);
        } else {
            $notice = 'guide_created_pdf_error';
        }

        $this->redirect_with_notice($return_url, $notice);
    }

    private function posted_return_url()
    {
        $url = esc_url_raw(wp_unslash($_POST['ecyg_return_url'] ?? ''));
        if (!$url) {
            $url = $this->get_app_url();
        }

        return remove_query_arg(array('ecyg_notice', 'ecyg_pdf'), $url);
    }

    private function redirect_with_notice($url, $notice)
    {
        wp_safe_redirect(add_query_arg('ecyg_notice', $notice, $url));
        exit;
    }

    public function ajax_lookup_client()
    {
        if (!current_user_can('edit_posts')) {
            wp_send_json_error(array('message' => 'Permiso denegado'), 403);
        }

        check_ajax_referer('ecyg_lookup_client', 'nonce');

        $type = sanitize_key(wp_unslash($_POST['document_type'] ?? ''));
        $number = $this->normalize_document_number($_POST['document_number'] ?? '');
        if (!$number) {
            wp_send_json_success(array('found' => false));
        }

        $client_id = $this->find_client_by_document($number, $type);
        if (!$client_id) {
            wp_send_json_success(array('found' => false));
        }

        wp_send_json_success(
            array(
                'found' => true,
                'client_id' => $client_id,
                'client' => $this->get_client_snapshot($client_id),
            )
        );
    }

    private function sanitize_client_payload($raw)
    {
        $raw = is_array($raw) ? wp_unslash($raw) : array();
        $type = sanitize_key($raw['document_type'] ?? 'cedula');
        $types = $this->document_types();
        if (!isset($types[$type])) {
            $type = 'cedula';
        }

        return array(
            'name' => sanitize_text_field($raw['name'] ?? ''),
            'document_type' => $type,
            'document_number' => $this->normalize_document_number($raw['document_number'] ?? ''),
            'phone' => sanitize_text_field($raw['phone'] ?? ''),
            'state' => sanitize_text_field($raw['state'] ?? ''),
            'city' => sanitize_text_field($raw['city'] ?? ''),
            'address' => sanitize_textarea_field($raw['address'] ?? ''),
        );
    }

    private function is_valid_client_payload($client)
    {
        return !empty($client['name'])
            && !empty($client['document_type'])
            && !empty($client['document_number'])
            && !empty($client['phone'])
            && !empty($client['state'])
            && !empty($client['city'])
            && !empty($client['address']);
    }

    private function sanitize_packages($raw)
    {
        $raw = is_array($raw) ? wp_unslash($raw) : array();
        $packages = array();

        foreach ($raw as $item) {
            if (!is_array($item)) {
                continue;
            }

            $quantity = $this->parse_decimal($item['quantity'] ?? 0);
            $price = $this->parse_decimal($item['unit_price'] ?? 0);
            $description = sanitize_text_field($item['description'] ?? '');

            if ($quantity <= 0 || $price < 0 || '' === $description) {
                continue;
            }

            $packages[] = array(
                'quantity' => $quantity,
                'description' => $description,
                'unit_price' => $price,
                'total' => round($quantity * $price, 2),
            );
        }

        return $packages;
    }

    private function parse_decimal($value)
    {
        $value = trim((string) $value);
        $value = preg_replace('/[^0-9,.\-]/', '', $value);
        if ('' === $value || '-' === $value) {
            return 0.0;
        }

        $last_comma = strrpos($value, ',');
        $last_dot = strrpos($value, '.');
        $decimal_pos = max(false === $last_comma ? -1 : $last_comma, false === $last_dot ? -1 : $last_dot);

        if ($decimal_pos >= 0) {
            $integer = preg_replace('/[^0-9\-]/', '', substr($value, 0, $decimal_pos));
            $decimal = preg_replace('/[^0-9]/', '', substr($value, $decimal_pos + 1));
            $value = $integer . '.' . $decimal;
        }

        return (float) $value;
    }

    private function normalize_document_number($value)
    {
        $value = strtoupper(sanitize_text_field(wp_unslash($value)));
        $value = preg_replace('/[^A-Z0-9\-]/', '', $value);

        return $value;
    }

    private function document_types()
    {
        return array(
            'cedula' => 'Cedula',
            'rif' => 'RIF',
            'pasaporte' => 'Pasaporte',
        );
    }

    private function document_type_label($type)
    {
        $types = $this->document_types();

        return $types[$type] ?? $type;
    }

    private function save_client($client, $client_id = 0)
    {
        $post_data = array(
            'post_type' => self::CLIENT_CPT,
            'post_status' => 'publish',
            'post_title' => $client['name'],
        );

        if ($client_id) {
            $post_data['ID'] = $client_id;
            $result = wp_update_post($post_data, true);
        } else {
            $result = wp_insert_post($post_data, true);
        }

        if (is_wp_error($result) || !$result) {
            return 0;
        }

        foreach ($client as $key => $value) {
            update_post_meta($result, '_ecyg_' . $key, $value);
        }

        return absint($result);
    }

    private function find_client_by_document($number, $type = '')
    {
        $number = $this->normalize_document_number($number);
        if (!$number) {
            return 0;
        }

        $meta_query = array(
            array(
                'key' => '_ecyg_document_number',
                'value' => $number,
                'compare' => '=',
            ),
        );

        if ($type) {
            $meta_query[] = array(
                'key' => '_ecyg_document_type',
                'value' => sanitize_key($type),
                'compare' => '=',
            );
        }

        $query = new WP_Query(
            array(
                'post_type' => self::CLIENT_CPT,
                'post_status' => 'any',
                'posts_per_page' => 1,
                'fields' => 'ids',
                'meta_query' => $meta_query,
                'no_found_rows' => true,
            )
        );

        return $query->posts ? absint($query->posts[0]) : 0;
    }

    private function get_client_snapshot($client_id)
    {
        $post = get_post($client_id);

        return array(
            'name' => get_post_meta($client_id, '_ecyg_name', true) ?: ($post ? $post->post_title : ''),
            'document_type' => get_post_meta($client_id, '_ecyg_document_type', true),
            'document_number' => get_post_meta($client_id, '_ecyg_document_number', true),
            'phone' => get_post_meta($client_id, '_ecyg_phone', true),
            'state' => get_post_meta($client_id, '_ecyg_state', true),
            'city' => get_post_meta($client_id, '_ecyg_city', true),
            'address' => get_post_meta($client_id, '_ecyg_address', true),
        );
    }

    private function get_client_destination($client)
    {
        $parts = array_filter(array($client['address'] ?? '', $client['city'] ?? '', $client['state'] ?? ''));

        return implode(', ', $parts);
    }

    private function format_guide_number($sequence, $prefix)
    {
        return $prefix . str_pad((string) absint($sequence), 6, '0', STR_PAD_LEFT);
    }

    private function format_money($amount)
    {
        return number_format((float) $amount, 2, ',', '.');
    }

    private function get_app_url()
    {
        $page_id = absint(get_option(self::PAGE_OPTION));
        if ($page_id && get_post($page_id)) {
            return get_permalink($page_id);
        }

        return get_permalink();
    }

   private function generate_guide_pdf($guide_id)
    {
        $guide_number = get_post_meta($guide_id, '_ecyg_guide_number', true);
        $issue_date = get_post_meta($guide_id, '_ecyg_issue_date', true);
        
        $client = get_post_meta($guide_id, '_ecyg_client_snapshot', true);
        // NUEVO: Rescatamos los datos del remitente
        $sender = get_post_meta($guide_id, '_ecyg_sender_snapshot', true); 
        
        $packages = get_post_meta($guide_id, '_ecyg_packages', true);
        $total = (float) get_post_meta($guide_id, '_ecyg_total', true);

        if (!is_array($client) || !is_array($packages)) {
            return false;
        }

        $upload = wp_upload_dir();
        if (!empty($upload['error'])) {
            return false;
        }

        $dir = trailingslashit($upload['basedir']) . 'envios-cyg-guias/guias';
        if (!wp_mkdir_p($dir)) {
            return false;
        }

        $filename = sanitize_file_name('guia-' . $guide_number . '-' . $guide_id . '.pdf');
        $path = trailingslashit($dir) . $filename;
        $url = trailingslashit($upload['baseurl']) . 'envios-cyg-guias/guias/' . $filename;

        $pdf = new ECYG_PDF_Document('L');
        $chunks = array_chunk($packages, 6);
        $page_count = max(1, count($chunks));

        foreach ($chunks as $index => $items) {
            $pdf->add_page();
            $this->draw_pdf_page($pdf, array(
                'guide_number' => $guide_number,
                'issue_date' => $issue_date,
                'client' => $client,
                'sender' => $sender, // NUEVO: Pasamos el remitente al renderizador
                'items' => $items,
                'total' => $total,
                'page' => $index + 1,
                'page_count' => $page_count,
                'is_last_page' => ($index + 1) === $page_count,
            ));
        }

        if (!$pdf->write($path)) {
            return false;
        }

        return array(
            'path' => $path,
            'url' => $url,
        );
    }

    private function draw_pdf_page(ECYG_PDF_Document $pdf, $data)
    {
        $options = $this->get_options();
        $logo_path = $this->get_local_image_path_from_url($options['company_logo_url']);
        
        $client = $data['client'];
        $client_dest = $this->get_client_destination($client);
        
        $sender = is_array($data['sender']) ? $data['sender'] : array();
        $sender_dest = $sender ? $this->get_client_destination($sender) : '';

        // 1. DIBUJO DE CABECERA (Logo, empresa, RIF, etc)
        if ($logo_path) {
            $pdf->image($logo_path, 38, 26, 118, 76);
            $text_x = 184;
            $rif_y = 43;
            $address_y = 63;
            $contact_y = 112;
            $pdf->line(170, 30, 170, 104, 1.2);
        } else {
            $pdf->text(40, 38, $options['company_name'], 22, 'B');
            $text_x = 40;
            $rif_y = 66;
            $address_y = 86;
            $contact_y = 126;
        }

        $pdf->text($text_x, $rif_y, 'RIF: ' . $options['company_rif'], 15, 'B');
        $pdf->multi_text($text_x, $address_y, $options['company_address'], 13, '', 350, 16);
        if (!empty($options['company_contact'])) {
            $pdf->text($text_x, $contact_y, 'Contacto: ' . $options['company_contact'], 11, '');
        }

        $pdf->text(610, 44, 'GUIA N: ' . $data['guide_number'], 16, 'B');
        if ($data['page_count'] > 1) {
            $pdf->text(610, 66, 'Pagina ' . $data['page'] . ' de ' . $data['page_count'], 10, '');
        }

        // 2. FECHA DE EMISIÓN
        $pdf->text(40, 138, 'FECHA:', 18, 'B');
        $pdf->text(118, 139, $this->format_date($data['issue_date']), 14, '');
        $pdf->line(116, 156, 255, 156, 1);
        // AÑADIR ESTO:
        $pdf->text(290, 138, 'TIPO DE PAGO:', 18, 'B');
        $pdf->text(430, 139, strtoupper(get_post_meta($guide_id, '_ecyg_payment_type', true) ?: 'PAGADO'), 14, '');
        $pdf->line(428, 156, 560, 156, 1);

        // 3. DATOS DE LOS CLIENTES (NUEVO DISEÑO EN DOS COLUMNAS)
        
        // COLUMNA IZQUIERDA: REMITENTE
        $pdf->text(40, 172, 'REMITENTE', 14, 'B');
        $pdf->line(40, 178, 380, 178, 1);
        
        $pdf->text(40, 194, 'Nombre:', 12, 'B');
        $pdf->text(95, 194, isset($sender['name']) ? $sender['name'] : '', 11, '');
        
        $pdf->text(40, 214, 'CI/RIF:', 12, 'B');
        $pdf->text(95, 214, isset($sender['document_number']) ? $sender['document_number'] : '', 11, '');
        
        $pdf->text(40, 234, 'Telefono:', 12, 'B');
        $pdf->text(95, 234, isset($sender['phone']) ? $sender['phone'] : '', 11, '');
        
        $pdf->text(40, 254, 'Direccion:', 12, 'B');
        $pdf->multi_text(105, 254, $sender_dest, 10, '', 275, 12);

        // COLUMNA DERECHA: DESTINATARIO
        $pdf->text(410, 172, 'DESTINATARIO', 14, 'B');
        $pdf->line(410, 178, 752, 178, 1);

        $pdf->text(410, 194, 'Nombre:', 12, 'B');
        $pdf->text(475, 194, isset($client['name']) ? $client['name'] : '', 11, '');
        
        $pdf->text(410, 214, 'CI/RIF:', 12, 'B');
        $pdf->text(475, 214, isset($client['document_number']) ? $client['document_number'] : '', 11, '');
        
        $pdf->text(410, 234, 'Telefono:', 12, 'B');
        $pdf->text(475, 234, isset($client['phone']) ? $client['phone'] : '', 11, '');
        
        $pdf->text(410, 254, 'Direccion:', 12, 'B');
        $pdf->multi_text(485, 254, $client_dest, 10, '', 265, 12);

        // Línea sútil que cierra los encabezados antes de la tabla
        $pdf->line(40, 282, 752, 282, 1);

        // 4. TABLA DE PAQUETES
        $x = 40;
        $y = 296;
        $columns = array(80, 430, 100, 102);
        $row_h = 38;
        $head_h = 30;
        $table_w = array_sum($columns);

        $pdf->rect($x, $y, $table_w, $head_h);
        $pdf->text($x + 22, $y + 20, 'CANT.', 14, 'B');
        $pdf->text($x + $columns[0] + 90, $y + 20, 'CONCEPTO / DESCRIPCION', 14, 'B');
        $pdf->text($x + $columns[0] + $columns[1] + 18, $y + 20, 'P. UNIT.', 14, 'B');
        $pdf->text($x + $columns[0] + $columns[1] + $columns[2] + 25, $y + 20, 'TOTAL', 14, 'B');

        $col_x = $x;
        foreach ($columns as $column_w) {
            $pdf->line($col_x, $y, $col_x, $y + $head_h + ($row_h * 6), 1);
            $col_x += $column_w;
        }
        $pdf->line($col_x, $y, $col_x, $y + $head_h + ($row_h * 6), 1);

        $current_y = $y + $head_h;
        for ($i = 0; $i < 6; $i++) {
            $pdf->rect($x, $current_y, $table_w, $row_h);
            if (isset($data['items'][$i])) {
                $item = $data['items'][$i];
                $pdf->text($x + 28, $current_y + 24, $this->trim_number($item['quantity']), 12, '');
                $pdf->multi_text($x + $columns[0] + 10, $current_y + 14, $item['description'], 10, '', $columns[1] - 20, 12);
                $pdf->text($x + $columns[0] + $columns[1] + 10, $current_y + 24, $this->format_money($item['unit_price']), 11, '');
                $pdf->text($x + $columns[0] + $columns[1] + $columns[2] + 10, $current_y + 24, $this->format_money($item['total']), 11, '');
            }
            $current_y += $row_h;
        }

        if ($data['is_last_page']) {
            $pdf->text(525, 570, 'TOTAL GENERAL:', 13, 'B');
            $pdf->text(655, 570, $this->format_money($data['total']), 13, 'B');
        }

        /*$pdf->line(40, 570, 390, 570, 1);
        $pdf->text(675, 570, 'CLIENTE', 16, 'B');*/
    }

    private function format_date($date)
    {
        $timestamp = strtotime($date);
        if (!$timestamp) {
            return $date;
        }

        return date_i18n('d/m/Y', $timestamp);
    }

    private function trim_number($number)
    {
        $number = (float) $number;
        if (floor($number) === $number) {
            return (string) (int) $number;
        }

        return rtrim(rtrim(number_format($number, 2, ',', '.'), '0'), ',');
    }

    private function get_local_image_path_from_url($url)
    {
        if (!$url) {
            return '';
        }

        $attachment_id = attachment_url_to_postid($url);
        if ($attachment_id) {
            $path = get_attached_file($attachment_id);
            return $this->prepare_pdf_image($path);
        }

        $upload = wp_upload_dir();
        if (!empty($upload['baseurl']) && 0 === strpos($url, $upload['baseurl'])) {
            $path = str_replace($upload['baseurl'], $upload['basedir'], $url);
            return $this->prepare_pdf_image($path);
        }

        return '';
    }

    private function prepare_pdf_image($path)
    {
        if (!$path || !file_exists($path)) {
            return '';
        }

        $info = @getimagesize($path);
        if (!$info) {
            return '';
        }

        if (IMAGETYPE_JPEG === $info[2]) {
            return $path;
        }

        return $this->convert_image_to_jpeg($path);
    }

    private function convert_image_to_jpeg($path)
    {
        if (!function_exists('wp_get_image_editor')) {
            require_once ABSPATH . 'wp-admin/includes/image.php';
        }

        $upload = wp_upload_dir();
        if (!empty($upload['error'])) {
            return '';
        }

        $dir = trailingslashit($upload['basedir']) . 'envios-cyg-guias/logo-cache';
        if (!wp_mkdir_p($dir)) {
            return '';
        }

        $cache_key = md5($path . '|' . filemtime($path));
        $cache_path = trailingslashit($dir) . $cache_key . '.jpg';
        if (file_exists($cache_path)) {
            return $cache_path;
        }

        $editor = wp_get_image_editor($path);
        if (is_wp_error($editor)) {
            return '';
        }

        $editor->set_quality(90);
        $saved = $editor->save($cache_path, 'image/jpeg');

        return is_wp_error($saved) ? '' : $cache_path;
    }

    public function register_meta_boxes()
    {
        add_meta_box('ecyg_client_details', 'Datos del cliente', array($this, 'render_client_meta_box'), self::CLIENT_CPT, 'normal', 'high');
        add_meta_box('ecyg_guide_details', 'Datos de la guia', array($this, 'render_guide_meta_box'), self::GUIDE_CPT, 'normal', 'high');
    }

    public function render_client_meta_box($post)
    {
        wp_nonce_field('ecyg_save_client_meta', 'ecyg_client_nonce');
        $this->render_client_fields($this->get_client_snapshot($post->ID));
    }

    public function save_client_meta_box($post_id)
    {
        if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) {
            return;
        }

        if (!isset($_POST['ecyg_client_nonce']) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['ecyg_client_nonce'])), 'ecyg_save_client_meta')) {
            return;
        }

        if (!current_user_can('edit_post', $post_id)) {
            return;
        }

        $client = $this->sanitize_client_payload($_POST['client'] ?? array());
        if ($this->is_valid_client_payload($client)) {
            $this->save_client($client, $post_id);
        }
    }

    public function render_guide_meta_box($post)
    {
        $guide_number = get_post_meta($post->ID, '_ecyg_guide_number', true);
        $issue_date = get_post_meta($post->ID, '_ecyg_issue_date', true);
        $client = get_post_meta($post->ID, '_ecyg_client_snapshot', true);
        $packages = get_post_meta($post->ID, '_ecyg_packages', true);
        $total = get_post_meta($post->ID, '_ecyg_total', true);
        $pdf_url = get_post_meta($post->ID, '_ecyg_pdf_url', true);

        echo '<p><strong>Numero de guia:</strong> ' . esc_html($guide_number) . '</p>';
        echo '<p><strong>Fecha:</strong> ' . esc_html($this->format_date($issue_date)) . '</p>';
        if (is_array($client)) {
            echo '<p><strong>Cliente:</strong> ' . esc_html($client['name'] ?? '') . ' - ' . esc_html($client['document_number'] ?? '') . '</p>';
            echo '<p><strong>Telefono:</strong> ' . esc_html($client['phone'] ?? '') . '</p>';
            echo '<p><strong>Destino:</strong> ' . esc_html($this->get_client_destination($client)) . '</p>';
        }

        if (is_array($packages)) {
            echo '<table class="widefat striped"><thead><tr><th>Cant.</th><th>Descripcion</th><th>Precio unit.</th><th>Total</th></tr></thead><tbody>';
            foreach ($packages as $package) {
                echo '<tr>';
                echo '<td>' . esc_html($this->trim_number($package['quantity'])) . '</td>';
                echo '<td>' . esc_html($package['description']) . '</td>';
                echo '<td>' . esc_html($this->format_money($package['unit_price'])) . '</td>';
                echo '<td>' . esc_html($this->format_money($package['total'])) . '</td>';
                echo '</tr>';
            }
            echo '</tbody></table>';
            echo '</br><p><strong>Total general:</strong> ' . esc_html($this->format_money($total)) . '</p>';
        }

        if ($pdf_url) {
            echo '<p><a class="button button-primary" target="_blank" rel="noopener" href="' . esc_url($pdf_url) . '">Abrir PDF</a></p>';
        }
    }

    public function client_columns($columns)
    {
        $new = array();
        foreach ($columns as $key => $label) {
            $new[$key] = $label;
            if ('title' === $key) {
                $new['document'] = 'Documento';
                $new['phone'] = 'Telefono';
                $new['city'] = 'Ciudad';
            }
        }

        return $new;
    }

    public function render_client_column($column, $post_id)
    {
        if ('document' === $column) {
            $type = get_post_meta($post_id, '_ecyg_document_type', true);
            echo esc_html($this->document_type_label($type) . ': ' . get_post_meta($post_id, '_ecyg_document_number', true));
        }
        if ('phone' === $column) {
            echo esc_html(get_post_meta($post_id, '_ecyg_phone', true));
        }
        if ('city' === $column) {
            echo esc_html(get_post_meta($post_id, '_ecyg_city', true));
        }
    }

    public function guide_columns($columns)
    {
        $new = array();
        foreach ($columns as $key => $label) {
            $new[$key] = $label;
            if ('title' === $key) {
                $new['guide_number'] = 'Numero';
                $new['issue_date'] = 'Fecha';
                $new['client'] = 'Cliente';
                $new['total'] = 'Total';
                $new['pdf'] = 'PDF';
            }
        }

        return $new;
    }

    public function render_guide_column($column, $post_id)
    {
        if ('guide_number' === $column) {
            echo esc_html(get_post_meta($post_id, '_ecyg_guide_number', true));
        }
        if ('issue_date' === $column) {
            echo esc_html($this->format_date(get_post_meta($post_id, '_ecyg_issue_date', true)));
        }
        if ('client' === $column) {
            $client = get_post_meta($post_id, '_ecyg_client_snapshot', true);
            echo esc_html(is_array($client) ? ($client['name'] ?? '') : '');
        }
        if ('total' === $column) {
            echo esc_html($this->format_money(get_post_meta($post_id, '_ecyg_total', true)));
        }
        if ('pdf' === $column) {
            $pdf_url = get_post_meta($post_id, '_ecyg_pdf_url', true);
            if ($pdf_url) {
                echo '<a href="' . esc_url($pdf_url) . '" target="_blank" rel="noopener">Abrir</a>';
            }
        }
    }

    /*REPORTES */
    /**
 * Renderiza la interfaz de búsqueda de reportes arriba del formulario principal
 */
public function render_report_ui()
{
    ?>
    <div class="ecyg-report-container" style="margin-bottom: 25px;">
        <button type="button" class="ecyg-button" id="ecyg-toggle-report-btn" style="background-color: #ff9393;">📊 Remesa/Historial</button>
        
        <div id="ecyg-report-panel" style="display: none; margin-top: 15px; padding: 20px; background: #fafafa; border: 1px solid #e0e0e0; border-radius: 6px;">
            <h4 style="margin-top:0; margin-bottom:15px; color:#333;">Consultar Guías por Rango de Fecha</h4>
            <div style="display: flex; gap: 15px; align-items: flex-end; flex-wrap: wrap;">
                <label style="display:flex; flex-direction:column; gap:5px;">
                    <span style="font-size:12px; font-weight:bold; color:#555;">Desde:</span>
                    <input type="date" id="ecyg-report-start" style="padding:6px; border:1px solid #ccc; border-radius:4px;">
                </label>
                <label style="display:flex; flex-direction:column; gap:5px;">
                    <span style="font-size:12px; font-weight:bold; color:#555;">Hasta:</span>
                    <input type="date" id="ecyg-report-end" style="padding:6px; border:1px solid #ccc; border-radius:4px;">
                </label>
                <button type="button" class="ecyg-button" id="ecyg-generate-report-btn" style="height:34px;">Buscar Guías</button>
                <button type="button" class="ecyg-button" id="ecyg-pdf-report-btn" style="background-color: #d9534f; height:34px; display: none;">🖨️ Imprimir Reporte (PDF)</button>
            </div>
            <div id="ecyg-report-results" style="margin-top: 20px; overflow-x: auto;"></div>
        </div>
    </div>
    <?php
}

/**
     * Consulta las guías en la base de datos por rango de fechas
     */
    private function query_guides_by_date_range($start, $end)
    {
        // Limpiamos los strings de las fechas (YYYY-MM-DD)
        $start_date = sanitize_text_field(trim($start));
        $end_date   = sanitize_text_field(trim($end));

        $args = array(
            'post_type'      => self::GUIDE_CPT,
            'post_status'    => 'publish',
            'posts_per_page' => -1,
            'meta_query'     => array(
                array(
                    'key'     => '_ecyg_issue_date',
                    'value'   => array($start_date, $end_date),
                    'compare' => 'BETWEEN',
                    'type'    => 'CHAR', // <-- CLAVE: Obliga a comparar como texto para evitar bugs de MySQL
                ),
            ),
        );

        $query = new WP_Query($args);
        $results = array();

        if ($query->have_posts()) {
            while ($query->have_posts()) {
                $query->the_post();
                $id = get_the_ID();

                $client = get_post_meta($id, '_ecyg_client_snapshot', true) ?: array();
                $sender = get_post_meta($id, '_ecyg_sender_snapshot', true) ?: array();
                $packages = get_post_meta($id, '_ecyg_packages', true) ?: array();
                $total = (float) get_post_meta($id, '_ecyg_total', true);
                $payment_type = get_post_meta($id, '_ecyg_payment_type', true) ?: 'Pagado';
                $guide_number = get_post_meta($id, '_ecyg_guide_number', true) ?: get_the_title();

                $descriptions = array();
                $total_qty = 0;

                if (is_array($packages)) {
                    foreach ($packages as $pkg) {
                        $descriptions[] = $pkg['description'] ?? '';
                        $total_qty += (float) ($pkg['quantity'] ?? 0);
                    }
                }

                $results[] = array(
                    'number'       => $guide_number,
                    'sender'       => $sender['name'] ?? 'N/A',
                    'client'       => $client['name'] ?? 'N/A',
                    'city'         => $client['city'] ?? 'N/A',
                    'description'  => implode(', ', $descriptions),
                    'quantity'     => $total_qty,
                    'payment_type' => $payment_type,
                    'total'        => $total,
                );
            }
            wp_reset_postdata();
        }
        
        return $results;
    }
/**
 * Endpoint AJAX que procesa y muestra la tabla en pantalla
 */
public function ajax_get_report()
    {
        check_ajax_referer('ecyg_lookup_client', 'nonce');

        $start = sanitize_text_field($_POST['start_date'] ?? '');
        $end = sanitize_text_field($_POST['end_date'] ?? '');

        if (!$start || !$end) {
            wp_send_json_error('Por favor selecciona un rango de fechas válido.');
        }

        $guides = $this->query_guides_by_date_range($start, $end);

        ob_start();

        // ==========================================
        // INICIO BLOQUE DE DEBUG
        // ==========================================
        /*echo '<div style="background:#fff3cd; color:#856404; padding:15px; border:1px solid #ffeeba; margin-bottom:15px; font-family:monospace; font-size:12px; border-radius: 4px;">';
        echo '<strong style="font-size:14px;">🛠️ MODO DEBUG ACTIVADO</strong><br><br>';
        echo '<strong>1. Fechas recibidas del buscador (JS):</strong><br>';
        echo '- Desde: <code>' . esc_html($start) . '</code><br>';
        echo '- Hasta: <code>' . esc_html($end) . '</code><br><br>';
        
        echo '<strong>2. ¿Cómo están guardadas las fechas en la Base de Datos? (Últimas 3 guías):</strong><br>';
        
        $recent_guides = get_posts(array(
            'post_type' => self::GUIDE_CPT, 
            'numberposts' => 3, 
            'post_status' => 'publish',
            'orderby' => 'date',
            'order' => 'DESC'
        ));

        if (empty($recent_guides)) {
            echo '<em>No hay guías registradas en el sistema aún.</em><br>';
        } else {
            foreach($recent_guides as $r) {
                $fecha_bd = get_post_meta($r->ID, '_ecyg_issue_date', true);
                echo '- Guía ID ' . $r->ID . ' (Creada el ' . $r->post_date . ') -> <code>' . esc_html($fecha_bd) . '</code><br>';
            }
        }
        
        echo '<br><strong>3. Total de guías encontradas en la consulta actual:</strong> <code>' . count($guides) . '</code>';
        echo '</div>';*/
        // ==========================================
        // FIN BLOQUE DE DEBUG
        // ==========================================

        if (empty($guides)) {
            echo '<p style="color:#777; font-style:italic;">No se encontraron guías en el rango de fechas seleccionado.</p>';
            $html = ob_get_clean();
            wp_send_json_success(array('html' => $html, 'count' => 0));
        }

        ?>
        <table style="width:100%; border-collapse: collapse; margin-top:10px; font-size:13px; text-align:left;">
            <thead>
                <tr style="background:#2c3e50; color:#fff;">
                    <th style="padding:10px; border:1px solid #ddd;">Guía N°</th>
                    <th style="padding:10px; border:1px solid #ddd;">Remitente</th>
                    <th style="padding:10px; border:1px solid #ddd;">Destinatario</th>
                    <th style="padding:10px; border:1px solid #ddd;">Ciudad</th>
                    <th style="padding:10px; border:1px solid #ddd;">Descripción</th>
                    <th style="padding:10px; border:1px solid #ddd; text-align:center;">Cant.</th>
                    <th style="padding:10px; border:1px solid #ddd;">Pago</th>
                    <th style="padding:10px; border:1px solid #ddd; text-align:right;">Monto</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($guides as $g) : ?>
                    <tr style="border-bottom:1px solid #eee; background:#fff;">
                        <td style="padding:8px; border:1px solid #ddd; font-weight:bold;"><?php echo esc_html($g['number']); ?></td>
                        <td style="padding:8px; border:1px solid #ddd;"><?php echo esc_html($g['sender']); ?></td>
                        <td style="padding:8px; border:1px solid #ddd;"><?php echo esc_html($g['client']); ?></td>
                        <td style="padding:8px; border:1px solid #ddd;"><?php echo esc_html($g['city']); ?></td>
                        <td style="padding:8px; border:1px solid #ddd; color:#555;"><?php echo esc_html($g['description']); ?></td>
                        <td style="padding:8px; border:1px solid #ddd; text-align:center;"><?php echo esc_html($g['quantity']); ?></td>
                        <td style="padding:8px; border:1px solid #ddd;"><span style="padding:2px 6px; background:#e1f5fe; border-radius:3px; font-size:11px; font-weight:bold; color:#0288d1;"><?php echo esc_html($g['payment_type']); ?></span></td>
                        <td style="padding:8px; border:1px solid #ddd; text-align:right; font-weight:bold;"><?php echo esc_html($this->format_money($g['total'])); ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        <?php
        $html = ob_get_clean();
        wp_send_json_success(array('html' => $html, 'count' => count($guides)));
    }
/**
 * Captura la petición HTTP GET y sirve el PDF del reporte con el membrete corporativo
 */
public function handle_pdf_report_download()
    {
        if (!isset($_GET['ecyg_download_report']) || !isset($_GET['start']) || !isset($_GET['end'])) {
            return;
        }

        $start = sanitize_text_field($_GET['start']);
        $end = sanitize_text_field($_GET['end']);
        $guides = $this->query_guides_by_date_range($start, $end);

        // =====================================
        // DEBUG 1: Validar si hay datos
        // =====================================
        if (empty($guides)) {
            wp_die('<strong>ERROR DE REPORTE:</strong> No se encontraron guías en el rango consultado (' . esc_html($start) . ' al ' . esc_html($end) . '). La consulta retornó un array vacío.');
        }

        $pdf = new ECYG_PDF_Document('L');
        $chunks = array_chunk($guides, 11);
        $page_count = max(1, count($chunks));

        foreach ($chunks as $index => $items) {
            $pdf->add_page();
            $this->draw_pdf_report_page($pdf, array(
                'start'      => $start,
                'end'        => $end,
                'items'      => $items,
                'page'       => $index + 1,
                'page_count' => $page_count,
            ));
        }

        $upload = wp_upload_dir();

     // =====================================
        // DEBUG 2: Validar directorio de uploads
        // =====================================
        if (!empty($upload['error'])) {
            wp_die('<strong>ERROR DE DIRECTORIO:</strong> WordPress reporta el siguiente problema con la carpeta de subidas: ' . esc_html($upload['error']));
        }

        // NUEVO: Usamos la subcarpeta específica del plugin que ya sabemos que funciona
        $dir = trailingslashit($upload['basedir']) . 'envios-cyg-guias/guias';
        if (!wp_mkdir_p($dir)) {
            wp_die('<strong>ERROR DE CARPETA:</strong> No se pudo crear o acceder al directorio <code>' . esc_html($dir) . '</code>');
        }

        // Definimos la ruta temporal dentro de esa carpeta
        $temp_path = trailingslashit($dir) . 'temp_reporte_' . time() . '.pdf';
        
        $write_result = $pdf->write($temp_path);
        // =====================================
        // DEBUG 3: Validar escritura física
        // =====================================
        if (!$write_result) {
            wp_die('<strong>ERROR DE ESCRITURA:</strong> La clase de PDF no pudo guardar el archivo temporal. Ruta intentada: <code>' . esc_html($temp_path) . '</code>. Esto suele ser un problema de permisos en tu servidor.');
        }

        // Si todo sale bien, limpiamos cualquier espacio en blanco que algún plugin haya emitido
        if (ob_get_length()) {
            ob_clean();
        }

        // Forzamos las cabeceras HTTP y disparamos el PDF
        header('Content-Type: application/pdf');
        header('Content-Disposition: inline; filename="reporte-envios-' . $start . '-al-' . $end . '.pdf"');
        header('Content-Length: ' . filesize($temp_path));
        
        readfile($temp_path);
        
        @unlink($temp_path); // Borramos el temporal de inmediato
        exit;
    }
/**
 * Renderiza milimétricamente el membrete e imprime la tabla del reporte en el documento PDF
 */
private function draw_pdf_report_page(ECYG_PDF_Document $pdf, $data)
{
    $options = $this->get_options();
    $logo_path = $this->get_local_image_path_from_url($options['company_logo_url']);

    // 1. MEMBRETE IDÉNTICO AL DE LAS GUÍAS
    if ($logo_path) {
        $pdf->image($logo_path, 38, 26, 118, 76);
        $text_x = 184;
        $rif_y = 43;
        $address_y = 63;
        $contact_y = 112;
        $pdf->line(170, 30, 170, 104, 1.2);
    } else {
        $pdf->text(40, 38, $options['company_name'], 22, 'B');
        $text_x = 40;
        $rif_y = 66;
        $address_y = 86;
        $contact_y = 126;
    }

    $pdf->text($text_x, $rif_y, 'RIF: ' . $options['company_rif'], 15, 'B');
    $pdf->multi_text($text_x, $address_y, $options['company_address'], 13, '', 350, 16);
    if (!empty($options['company_contact'])) {
        $pdf->text($text_x, $contact_y, 'Contacto: ' . $options['company_contact'], 11, '');
    }

    // TÍTULO DEL REPORTE (Alineado a la derecha para no chocar con la dirección)
    $report_x = 550; // Antes estaba en 490
    
    $pdf->text($report_x, 44, 'REPORTE GENERAL DE ENVIOS', 16, 'B');
    $pdf->text($report_x, 66, 'Periodo: ' . $this->format_date($data['start']) . ' al ' . $this->format_date($data['end']), 11, '');
    
    if ($data['page_count'] > 1) {
        $pdf->text($report_x, 86, 'Pagina ' . $data['page'] . ' de ' . $data['page_count'], 10, '');
    }

    $pdf->line(40, 134, 752, 134, 1.5);

    // 2. CONSTRUCCIÓN DE LA TABLA DE REPORTES (Ancho total: 712)
    $x = 40;
    $y = 155;
    $columns = array(50, 105, 105, 80, 190, 37, 65, 80); 
    $table_w = array_sum($columns);
    $head_h = 26;
    $row_h = 32;

    // Encabezados
    $pdf->rect($x, $y, $table_w, $head_h);
    $pdf->text($x + 5, $y + 17, 'GUIA', 11, 'B');
    $pdf->text($x + 50 + 5, $y + 17, 'REMITENTE', 11, 'B');
    $pdf->text($x + 50 + 105 + 5, $y + 17, 'DESTINATARIO', 11, 'B');
    $pdf->text($x + 50 + 105 + 105 + 5, $y + 17, 'CIUDAD', 11, 'B');
    $pdf->text($x + 50 + 105 + 105 + 80 + 5, $y + 17, 'CONCEPTO / DESCRIPCION', 11, 'B');
    $pdf->text($x + 50 + 105 + 105 + 80 + 190 + 2, $y + 17, 'CANT', 11, 'B');
    $pdf->text($x + 50 + 105 + 105 + 80 + 190 + 37 + 5, $y + 17, 'PAGO', 11, 'B');
    $pdf->text($x + 50 + 105 + 105 + 80 + 190 + 37 + 65 + 15, $y + 17, 'MONTO', 11, 'B');

    // Grid vertical de columnas
    $col_x = $x;
    foreach ($columns as $w) {
        $pdf->line($col_x, $y, $col_x, $y + $head_h + ($row_h * count($data['items'])), 1);
        $col_x += $w;
    }
    $pdf->line($col_x, $y, $col_x, $y + $head_h + ($row_h * count($data['items'])), 1);

    // Filas de datos
    $current_y = $y + $head_h;
    foreach ($data['items'] as $item) {
        $pdf->rect($x, $current_y, $table_w, $row_h);

        $pdf->text($x + 5, $current_y + 20, $item['number'], 10, 'B');
        $pdf->text($x + 50 + 5, $current_y + 20, substr($item['sender'], 0, 16), 9, '');
        $pdf->text($x + 50 + 105 + 5, $current_y + 20, substr($item['client'], 0, 16), 9, '');
        $pdf->text($x + 50 + 105 + 105 + 5, $current_y + 20, substr($item['city'], 0, 12), 9, '');
        $pdf->text($x + 50 + 105 + 105 + 80 + 5, $current_y + 20, substr($item['description'], 0, 36), 9, '');
        $pdf->text($x + 50 + 105 + 105 + 80 + 190 + 12, $current_y + 20, $item['quantity'], 9, '');
        $pdf->text($x + 50 + 105 + 105 + 80 + 190 + 37 + 5, $current_y + 20, $item['payment_type'], 9, '');
        $pdf->text($x + 50 + 105 + 105 + 80 + 190 + 37 + 65 + 15, $current_y + 20, $this->format_money($item['total']), 9, 'B');

        $current_y += $row_h;
    }
}
}

final class ECYG_PDF_Document
{
    private $width;
    private $height;
    private $pages = array();
    private $current_page = -1;
    private $images = array();

    public function __construct($orientation = 'P')
    {
        if ('L' === strtoupper($orientation)) {
            $this->width = 792;
            $this->height = 612;
        } else {
            $this->width = 612;
            $this->height = 792;
        }
    }

    public function add_page()
    {
        $this->pages[] = '';
        $this->current_page = count($this->pages) - 1;
    }

    public function text($x, $y, $text, $size = 12, $style = '', $align = 'L', $max_width = 0)
    {
        if ($this->current_page < 0) {
            $this->add_page();
        }

        $font = ('B' === strtoupper($style)) ? 'F2' : 'F1';
        $text = $this->escape_text($text);
        $pdf_y = $this->height - $y;

        if ($max_width > 0 && in_array($align, array('R', 'C'), true)) {
            $estimated_width = strlen($text) * $size * 0.45;
            if ('R' === $align) {
                $x = $x + $max_width - $estimated_width;
            } else {
                $x = $x + (($max_width - $estimated_width) / 2);
            }
        }

        $this->append(sprintf("BT /%s %.2F Tf %.2F %.2F Td (%s) Tj ET\n", $font, $size, $x, $pdf_y, $text));
    }

    public function multi_text($x, $y, $text, $size = 12, $style = '', $max_width = 240, $line_height = 14)
    {
        $lines = $this->wrap_text($text, $size, $max_width);
        foreach ($lines as $index => $line) {
            $this->text($x, $y + ($index * $line_height), $line, $size, $style);
        }

        return count($lines) * $line_height;
    }

    public function line($x1, $y1, $x2, $y2, $width = 1)
    {
        $this->append(sprintf("%.2F w %.2F %.2F m %.2F %.2F l S\n", $width, $x1, $this->height - $y1, $x2, $this->height - $y2));
    }

    public function rect($x, $y, $width, $height)
    {
        $this->append(sprintf("%.2F %.2F %.2F %.2F re S\n", $x, $this->height - $y - $height, $width, $height));
    }

    public function image($path, $x, $y, $width, $height)
    {
        if (!file_exists($path)) {
            return;
        }

        $info = @getimagesize($path);
        if (!$info || IMAGETYPE_JPEG !== $info[2]) {
            return;
        }

        $key = md5($path);
        if (!isset($this->images[$key])) {
            $channels = $info['channels'] ?? 3;
            $color_space = 1 === $channels ? 'DeviceGray' : (4 === $channels ? 'DeviceCMYK' : 'DeviceRGB');
            $this->images[$key] = array(
                'name' => 'Im' . (count($this->images) + 1),
                'path' => $path,
                'width' => $info[0],
                'height' => $info[1],
                'color_space' => $color_space,
            );
        }

        $name = $this->images[$key]['name'];
        $pdf_y = $this->height - $y - $height;
        $this->append(sprintf("q %.2F 0 0 %.2F %.2F %.2F cm /%s Do Q\n", $width, $height, $x, $pdf_y, $name));
    }

    public function write($path)
    {
        if (!$this->pages) {
            $this->add_page();
        }

        $objects = array();
        $objects[1] = '';
        $objects[2] = '';
        $objects[3] = '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica /Encoding /WinAnsiEncoding >>';
        $objects[4] = '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica-Bold /Encoding /WinAnsiEncoding >>';

        $next_id = 5;
        $xobject_entries = array();
        foreach ($this->images as $key => $image) {
            $data = file_get_contents($image['path']);
            if (false === $data) {
                continue;
            }
            $image_id = $next_id++;
            $this->images[$key]['object_id'] = $image_id;
            $xobject_entries[] = '/' . $image['name'] . ' ' . $image_id . ' 0 R';
            $objects[$image_id] = sprintf(
                "<< /Type /XObject /Subtype /Image /Width %d /Height %d /ColorSpace /%s /BitsPerComponent 8 /Filter /DCTDecode /Length %d >>\nstream\n%s\nendstream",
                $image['width'],
                $image['height'],
                $image['color_space'],
                strlen($data),
                $data
            );
        }

        $page_ids = array();
        foreach ($this->pages as $page_content) {
            $content_id = $next_id++;
            $page_id = $next_id++;
            $objects[$content_id] = sprintf("<< /Length %d >>\nstream\n%s\nendstream", strlen($page_content), $page_content);
            $objects[$page_id] = sprintf(
                '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 %.2F %.2F] /Resources << /Font << /F1 3 0 R /F2 4 0 R >> /XObject << %s >> >> /Contents %d 0 R >>',
                $this->width,
                $this->height,
                implode(' ', $xobject_entries),
                $content_id
            );
            $page_ids[] = $page_id;
        }

        $objects[1] = '<< /Type /Catalog /Pages 2 0 R >>';
        $kids = array_map(
            function ($page_id) {
                return $page_id . ' 0 R';
            },
            $page_ids
        );
        $objects[2] = sprintf('<< /Type /Pages /Kids [ %s ] /Count %d >>', implode(' ', $kids), count($page_ids));

        ksort($objects);
        $max_id = max(array_keys($objects));
        $pdf = "%PDF-1.4\n%\xE2\xE3\xCF\xD3\n";
        $offsets = array(0 => 0);

        for ($id = 1; $id <= $max_id; $id++) {
            if (!isset($objects[$id])) {
                $objects[$id] = '';
            }
            $offsets[$id] = strlen($pdf);
            $pdf .= $id . " 0 obj\n" . $objects[$id] . "\nendobj\n";
        }

        $xref = strlen($pdf);
        $pdf .= "xref\n0 " . ($max_id + 1) . "\n";
        $pdf .= "0000000000 65535 f \n";
        for ($id = 1; $id <= $max_id; $id++) {
            $pdf .= sprintf("%010d 00000 n \n", $offsets[$id]);
        }
        $pdf .= "trailer\n<< /Size " . ($max_id + 1) . " /Root 1 0 R >>\n";
        $pdf .= "startxref\n" . $xref . "\n%%EOF";

        return false !== file_put_contents($path, $pdf);
    }

    private function append($content)
    {
        if ($this->current_page < 0) {
            $this->add_page();
        }

        $this->pages[$this->current_page] .= $content;
    }

    private function wrap_text($text, $size, $max_width)
    {
        $text = trim(preg_replace('/\s+/', ' ', (string) $text));
        if ('' === $text) {
            return array('');
        }

        $max_chars = max(10, (int) floor($max_width / max(1, $size * 0.48)));

        return explode("\n", wordwrap($text, $max_chars, "\n", true));
    }

    private function escape_text($text)
    {
        $text = (string) $text;
        if (function_exists('iconv')) {
            $converted = @iconv('UTF-8', 'Windows-1252//TRANSLIT', $text);
            if (false !== $converted) {
                $text = $converted;
            }
        } elseif (function_exists('utf8_decode')) {
            $text = utf8_decode($text);
        }

        return str_replace(array('\\', '(', ')', "\r"), array('\\\\', '\(', '\)', ''), $text);
    }
}

register_activation_hook(__FILE__, array('ECYG_Guias_Plugin', 'activate'));
register_deactivation_hook(__FILE__, array('ECYG_Guias_Plugin', 'deactivate'));
ECYG_Guias_Plugin::instance();
