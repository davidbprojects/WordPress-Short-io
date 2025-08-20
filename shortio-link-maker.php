<?php
/**
 * Plugin Name: Short.io Link Maker (QR + Output on Top)
 * Description: Create Short.io links (domain + domainId, optional cloaking & path), generate QR, and show the short link + QR at the top. Includes dry run and step-by-step transcript.
 * Version: 0.8.0
 */

if (!defined('ABSPATH')) exit;

function slm_steps_bootstrap() {
  class SLM_Steps_Plugin {
    const OPTS_API_KEY     = 'shortio_api_key';
    const OPTS_DOMAIN      = 'shortio_domain';       // e.g., bogar.click
    const OPTS_DOMAIN_ID   = 'shortio_domain_id';    // numeric id
    const OPTS_BASE_URL    = 'shortio_base_url';     // destination base url
    const OPTS_CLOAK_DEF   = 'shortio_cloak_default';// "1" or "0" (default unchecked)
    const NONCE            = 'shortio_link_maker_nonce';
    const PAGE_SLUG        = 'shortio-link-maker';

    public function __construct() {
      if (is_admin()) {
        add_action('admin_menu', [$this, 'add_site_settings_page']);
        if (is_multisite()) {
          add_action('network_admin_menu', [$this, 'add_network_settings_page']);
          add_action('network_admin_edit_' . self::PAGE_SLUG, [$this, 'save_network_settings']);
        }
        add_action('admin_init', [$this, 'register_site_settings']);
      }
      add_shortcode('shortio_link_form', [$this, 'shortcode_form']);
      add_action('wp_enqueue_scripts', [$this, 'enqueue_assets']);
    }

    /* ---------------- Assets (copy button JS) ---------------- */
    public function enqueue_assets() {
      if (!is_user_logged_in()) return;
      $js = "
        document.addEventListener('click', function(e){
          var btn = e.target.closest('[data-copy-target]');
          if(!btn) return;
          var sel = btn.getAttribute('data-copy-target');
          var el = document.querySelector(sel);
          if(!el) return;
          var text = el.textContent || el.value || '';
          navigator.clipboard.writeText(text).then(function(){
            var old = btn.textContent;
            btn.textContent = 'Copied!';
            setTimeout(function(){ btn.textContent = old; }, 1200);
          });
        });
      ";
      wp_register_script('slm-copy', '', [], null, true);
      wp_enqueue_script('slm-copy');
      wp_add_inline_script('slm-copy', $js);
    }

    /* ---------------- Admin: settings pages ---------------- */
    public function add_site_settings_page() {
      if (is_network_admin()) return;
      add_options_page('Short.io Link Maker','Short.io Link Maker','manage_options',self::PAGE_SLUG,[$this,'render_site_settings_page']);
    }
    public function add_network_settings_page() {
      add_submenu_page('settings.php','Short.io Link Maker','Short.io Link Maker','manage_network_options',self::PAGE_SLUG,[$this,'render_network_settings_page']);
    }
    public function register_site_settings() {
      register_setting(self::PAGE_SLUG, self::OPTS_API_KEY);
      register_setting(self::PAGE_SLUG, self::OPTS_DOMAIN);
      register_setting(self::PAGE_SLUG, self::OPTS_DOMAIN_ID);
      register_setting(self::PAGE_SLUG, self::OPTS_BASE_URL);
      register_setting(self::PAGE_SLUG, self::OPTS_CLOAK_DEF);
      if (get_option(self::OPTS_CLOAK_DEF, '') === '') update_option(self::OPTS_CLOAK_DEF, '0'); // default unchecked
    }
    public function render_site_settings_page() {
      if (!current_user_can('manage_options')) return; ?>
      <div class="wrap"><h1>Short.io Link Maker (Site Settings)</h1>
        <form method="post" action="options.php">
          <?php settings_fields(self::PAGE_SLUG); $this->render_fields(false); submit_button(); ?>
        </form>
      </div><?php
    }
    public function render_network_settings_page() {
      if (!current_user_can('manage_network_options')) return; ?>
      <div class="wrap"><h1>Short.io Link Maker (Network Settings)</h1>
        <form method="post" action="edit.php?action=<?php echo esc_attr(self::PAGE_SLUG); ?>">
          <?php wp_nonce_field(self::NONCE, self::NONCE); $this->render_fields(true); submit_button('Save Changes'); ?>
        </form>
      </div><?php
    }
    private function render_fields($network=false) {
      $api     = esc_attr($this->get_opt(self::OPTS_API_KEY, ''));
      $dom     = esc_attr($this->get_opt(self::OPTS_DOMAIN, ''));
      $domid   = esc_attr($this->get_opt(self::OPTS_DOMAIN_ID, ''));
      $base    = esc_url($this->get_opt(self::OPTS_BASE_URL, home_url('/')));
      $cloak_d = $this->get_opt(self::OPTS_CLOAK_DEF, '0');

      echo '<table class="form-table"><tbody>';
      printf('<tr><th><label>API Key</label></th><td><input type="password" name="%s" value="%s" class="regular-text" autocomplete="off" /><p class="description">Short.io API key.</p></td></tr>', esc_attr(self::OPTS_API_KEY), $api);
      printf('<tr><th><label>Domain</label></th><td><input type="text" name="%s" value="%s" class="regular-text" placeholder="bogar.click" /><p class="description">Your Short.io domain (required).</p></td></tr>', esc_attr(self::OPTS_DOMAIN), $dom);
      printf('<tr><th><label>Domain ID</label></th><td><input type="text" name="%s" value="%s" class="regular-text" placeholder="123456" /><p class="description">Numeric Domain ID (optional but recommended).</p></td></tr>', esc_attr(self::OPTS_DOMAIN_ID), $domid);
      printf('<tr><th><label>Destination Base URL</label></th><td><input type="url" name="%s" value="%s" class="regular-text" placeholder="%s" /><p class="description">Example: https://davidbogar.com/</p></td></tr>', esc_attr(self::OPTS_BASE_URL), $base, esc_attr(home_url('/')));
      echo '<tr><th><label>Default Cloaking</label></th><td>';
      printf('<label><input type="checkbox" name="%s" value="1" %s /> Enable link cloaking (iframe) by default</label>', esc_attr(self::OPTS_CLOAK_DEF), checked($cloak_d, '1', false));
      echo '<p class="description">Unchecked by default. Can be overridden per link on the form.</p></td></tr>';
      echo '</tbody></table>';
    }

    /* ---------------- Storage helpers ---------------- */
    private function get_opt($key, $default = '') {
      if (is_multisite() && is_network_admin()) {
        $v = get_site_option($key, null);
        return $v !== null ? $v : $default;
      }
      return get_option($key, $default);
    }
    private function get_runtime_opt($key, $default = '') {
      $v = get_option($key, '');
      if ($v === '' && is_multisite()) $v = get_site_option($key, '');
      return $v !== '' ? $v : $default;
    }
    private function update_opt($key, $val) {
      if (is_multisite() && is_network_admin()) return update_site_option($key, $val);
      return update_option($key, $val);
    }
    public function save_network_settings() {
      if (!current_user_can('manage_network_options')) wp_die('No permission');
      if (!isset($_POST[self::NONCE]) || !wp_verify_nonce($_POST[self::NONCE], self::NONCE)) wp_die('Security check failed');
      $fields = [self::OPTS_API_KEY,self::OPTS_DOMAIN,self::OPTS_DOMAIN_ID,self::OPTS_BASE_URL,self::OPTS_CLOAK_DEF];
      foreach ($fields as $f) {
        if (!isset($_POST[$f])) { if ($f === self::OPTS_CLOAK_DEF) $this->update_opt($f, '0'); continue; }
        $val = ($f===self::OPTS_BASE_URL) ? esc_url_raw(wp_unslash($_POST[$f])) : sanitize_text_field(wp_unslash($_POST[$f]));
        if ($f === self::OPTS_CLOAK_DEF) $val = ($val === '1') ? '1' : '0';
        $this->update_opt($f, $val);
      }
      wp_safe_redirect(add_query_arg(['page'=>self::PAGE_SLUG,'updated'=>'true'], network_admin_url('settings.php'))); exit;
    }

    /* ---------------- Shortcode & UI ---------------- */
    public function shortcode_form() {
      $cap = is_multisite() ? (is_network_admin() ? 'manage_network_options' : 'edit_pages') : 'edit_pages';
      if (!is_user_logged_in() || !current_user_can($cap)) return '<p>You must be logged in to use this tool.</p>';

      // Collect posted values (your custom field names)
      $fields = ['company','skill1','skill2','skill3','skill4','skill5','mytitle','yourtitle','slug'];
      $vals = [];
      foreach ($fields as $k) $vals[$k] = isset($_POST[$k]) ? sanitize_text_field(wp_unslash($_POST[$k])) : '';

      // Cloaking: default unchecked unless user checks it on submit
      $cloak_default = $this->get_runtime_opt(self::OPTS_CLOAK_DEF, '0') === '1';
      $cloak = isset($_POST[self::NONCE]) ? !empty($_POST['cloak']) : $cloak_default;

      $dry  = !empty($_POST['dry_run']);

      $notice_html = '';
      $top_box     = '';
      $steps_html  = '';

      $submitted = isset($_POST[self::NONCE]);
      if ($submitted) {
        if (!wp_verify_nonce($_POST[self::NONCE] ?? '', self::NONCE)) {
          $notice_html = "<div class='notice notice-error'><p>Security check failed (nonce). Please refresh and try again.</p></div>";
        } else {
          $result = $this->run_steps($vals, $dry, $cloak);
          $steps_html = $result['html'];

          // Build TOP BOX with short link + QR (if available), else destination URL
          $primary_url = $result['short_url'] ?: $result['dest_url'];
          if ($primary_url) {
            $esc = esc_html($primary_url);
            $url = esc_url($primary_url);

            $qr_img  = '';
            $qr_dl   = '';
            if (!empty($result['qr_data_uri'])) {
              $qr_img = '<img src="'.esc_attr($result['qr_data_uri']).'" alt="QR code" style="max-width:220px;height:auto;border:1px solid #e5e7eb;border-radius:8px;background:#fff;padding:8px;" />';
              $qr_dl  = '<a class="button" download="shortlink-qr.png" href="'.esc_attr($result['qr_data_uri']).'">Download PNG</a>';
            }

            $top_box = '
              <div class="slm-output" style="margin:18px 0;padding:14px 16px;border:2px solid #1e73be;border-radius:8px;background:#f3f9ff;">
                <strong>Short Link:</strong>
                <div style="display:flex;gap:10px;align-items:center;margin-top:8px;flex-wrap:wrap;">
                  <code id="slm-output-text" style="padding:6px 8px;background:#fff;border:1px solid #cbd5e1;border-radius:6px;word-break:break-all;display:inline-block;">'.$esc.'</code>
                  <button type="button" class="button" data-copy-target="#slm-output-text">Copy</button>
                  <a class="button button-primary" href="'.$url.'" target="_blank" rel="noopener">Open</a>
                  '.($qr_dl ?: '').'
                </div>
                '.($qr_img ? '<div style="margin-top:12px;">'.$qr_img.'</div>' : '').'
              </div>';
          }
        }
      }

      ob_start(); ?>
      <form method="post" style="max-width:900px;padding:16px;border:1px solid #e0e0e0;border-radius:8px;background:#fff;">
        <?php wp_nonce_field(self::NONCE, self::NONCE); ?>
        <h2 style="margin-top:0;">Create & Inspect Short Link</h2>

        <p><label>Company Name<br><input type="text" name="company" value="<?php echo esc_attr($vals['company']); ?>" required style="width:100%"></label></p>

        <div style="display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:12px;">
          <p><label>Skill 1<br><input type="text" name="skill1" value="<?php echo esc_attr($vals['skill1']); ?>" required style="width:100%"></label></p>
          <p><label>Skill 2<br><input type="text" name="skill2" value="<?php echo esc_attr($vals['skill2']); ?>" required style="width:100%"></label></p>
          <p><label>Skill 3<br><input type="text" name="skill3" value="<?php echo esc_attr($vals['skill3']); ?>" style="width:100%"></label></p>
          <p><label>Skill 4<br><input type="text" name="skill4" value="<?php echo esc_attr($vals['skill4']); ?>" style="width:100%"></label></p>
          <p><label>Skill 5<br><input type="text" name="skill5" value="<?php echo esc_attr($vals['skill5']); ?>" style="width:100%"></label></p>
        </div>

        <div style="display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:12px;">
          <p><label>My Title<br><input type="text" name="mytitle" value="<?php echo esc_attr($vals['mytitle']); ?>" style="width:100%"></label></p>
          <p><label>Company Title<br><input type="text" name="yourtitle" value="<?php echo esc_attr($vals['yourtitle']); ?>" style="width:100%"></label></p>
        </div>

        <p><label>Custom short path (optional)<br>
          <input type="text" name="slug" value="<?php echo esc_attr($vals['slug']); ?>" placeholder="company-or-company-role" style="width:100%">
          <small>Example: <code>company</code> → <code>https://yourshort.tld/company</code></small>
        </label></p>

        <p>
          <label><input type="checkbox" name="cloak" value="1" <?php checked($cloak, true); ?> /> Cloak this link (iframe)</label>
          <br><small>Default is unchecked. You can change the default in Settings.</small>
        </p>

        <p><label><input type="checkbox" name="dry_run" value="1" <?php checked($dry, true); ?> /> Dry run (don’t call Short.io, just show steps)</label></p>

        <p><button type="submit" name="shortio_submit" value="1" class="button button-primary">Create</button></p>
      </form>

      <?php echo $top_box; ?>
      <?php echo $notice_html; ?>
      <?php echo $steps_html; ?>
      <?php
      return ob_get_clean();
    }

    /* ---------------- Core: create link + QR + steps ---------------- */
    private function run_steps($data, $dry_run=false, $cloak=true) {
      // Resolve settings
      $api_key   = $this->get_runtime_opt(self::OPTS_API_KEY, '');
      $domain    = $this->get_runtime_opt(self::OPTS_DOMAIN, '');
      $domain_id = $this->get_runtime_opt(self::OPTS_DOMAIN_ID, '');
      $base_url  = trailingslashit($this->get_runtime_opt(self::OPTS_BASE_URL, home_url('/')));

      // STEP 1: Inputs
      $safe_inputs = [
        'company'   => $data['company'],
        'skill1'    => $data['skill1'],
        'skill2'    => $data['skill2'],
        'skill3'    => $data['skill3'],
        'skill4'    => $data['skill4'],
        'skill5'    => $data['skill5'],
        'mytitle'   => $data['mytitle'],
        'yourtitle' => $data['yourtitle'],
        'slug'      => $data['slug'],
        'cloak'     => $cloak ? 'true' : 'false',
      ];

      // STEP 2: Destination URL
      $query = [
        'company'   => $safe_inputs['company'],
        'skill1'    => $safe_inputs['skill1'],
        'skill2'    => $safe_inputs['skill2'],
        'skill3'    => $safe_inputs['skill3'],
        'skill4'    => $safe_inputs['skill4'],
        'skill5'    => $safe_inputs['skill5'],
        'mytitle'   => $safe_inputs['mytitle'],
        'yourtitle' => $safe_inputs['yourtitle'],
      ];
      $dest_url = add_query_arg(array_map('rawurlencode', $query), $base_url);

      // STEP 3: Build create payload (send BOTH domain and domainId)
      $endpoint = 'https://api.short.io/links';
      $payload = [
        'domain'      => $domain ?: 'YOUR-DOMAIN-HERE',
        'originalURL' => esc_url_raw($dest_url),
        'title'       => trim(($safe_inputs['yourtitle'] ? $safe_inputs['yourtitle'] : 'Link') . ' @ ' . $safe_inputs['company']),
        'cloaking'    => (bool)$cloak,
      ];
      if (!empty($domain_id))           $payload['domainId'] = (int)$domain_id;
      if (!empty($safe_inputs['slug'])) $payload['path']     = sanitize_title($safe_inputs['slug']);

      $masked_key     = $this->mask_key($api_key ?: 'NO-API-KEY');
      $pretty_payload = esc_html(wp_json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
      $curl_snippet   = sprintf(
        "curl -sS '%s' \\\n  -H 'Authorization: %s' \\\n  -H 'Content-Type: application/json' \\\n  -d '%s'",
        esc_html($endpoint),
        esc_html($masked_key),
        esc_html(wp_json_encode($payload, JSON_UNESCAPED_SLASHES))
      );

      // STEP 4: Call create (unless dry run), then request QR
      $resp_block  = '';
      $result_block= '';
      $short_url   = '';
      $link_id     = '';
      $qr_data_uri = '';

      $can_call = (!$dry_run && $api_key && $domain);

      if (!$can_call) {
        $resp_block = $dry_run
          ? "<pre>(dry run — no request was sent)</pre>"
          : "<div class='notice notice-warning'><p>Live call skipped: missing API settings (need API Key and Domain). Set them in Settings → Short.io Link Maker, or check “Dry run”.</p></div>";
      } else {
        // Create link
        $response = wp_remote_post($endpoint, [
          'headers' => [
            'Authorization' => $api_key,
            'Content-Type'  => 'application/json',
          ],
          'timeout' => 20,
          'body'    => wp_json_encode($payload),
        ]);

        if (is_wp_error($response)) {
          $resp_block = "<div class='notice notice-error'><p>HTTP error: " . esc_html($response->get_error_message()) . "</p></div>";
        } else {
          $code = wp_remote_retrieve_response_code($response);
          $body = wp_remote_retrieve_body($response);
          $json = json_decode($body, true);
          $pretty_json = $json ? wp_json_encode($json, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) : $body;

          $resp_block  = "<p><strong>HTTP Status (create):</strong> " . esc_html($code) . "</p>";
          $resp_block .= "<pre style='white-space:pre-wrap;'>" . esc_html($pretty_json) . "</pre>";

          if (is_array($json)) {
            if (!empty($json['shortURL']))   $short_url = esc_url($json['shortURL']);
            if (!empty($json['idString']))   $link_id   = sanitize_text_field($json['idString']);
          }

          // If created ok and we have the link ID, request the QR (PNG) via POST /links/qr/{idString}
          if ($link_id) {
            $qr_endpoint = 'https://api.short.io/links/qr/' . rawurlencode($link_id);
            $qr_payload  = [ 'type' => 'png', 'size' => 8 ]; // size must be < 100, per Short.io changelog
            $qr_resp = wp_remote_post($qr_endpoint, [
              'headers' => [
                'Authorization' => $api_key,
                'Content-Type'  => 'application/json',
                'Accept'        => 'application/json',
              ],
              'timeout' => 20,
              'body'    => wp_json_encode($qr_payload),
            ]);

            if (!is_wp_error($qr_resp)) {
              $qr_body  = wp_remote_retrieve_body($qr_resp);
              $qr_type  = '';
              $hdrs     = wp_remote_retrieve_headers($qr_resp);
              if (is_array($hdrs) && !empty($hdrs['content-type'])) $qr_type = $hdrs['content-type'];

              // Try JSON first (in case API returns base64 in JSON)
              $qr_json = json_decode($qr_body, true);
              if (is_array($qr_json)) {
                // Heuristics: look for keys that could hold data URI or raw base64
                $data = '';
                foreach (['data','content','image','base64'] as $k) {
                  if (!empty($qr_json[$k])) { $data = $qr_json[$k]; break; }
                }
                if ($data) {
                  if (strpos($data, 'data:image') === 0) {
                    $qr_data_uri = $data;
                  } else {
                    $qr_data_uri = 'data:image/png;base64,' . $data;
                  }
                }
              }

              // If not JSON or empty, assume binary image and build data URI
              if (!$qr_data_uri) {
                // Guess MIME from header; fallback to PNG
                $mime = (stripos($qr_type, 'image/') === 0) ? $qr_type : 'image/png';
                $qr_data_uri = 'data:' . $mime . ';base64,' . base64_encode($qr_body);
              }
            }

            if ($qr_data_uri) {
              $result_block .= "<p><strong>QR Code:</strong></p><p><img src='".esc_attr($qr_data_uri)."' alt='QR code' style='max-width:220px;height:auto;border:1px solid #e5e7eb;border-radius:8px;background:#fff;padding:8px;'></p>";
            }
          }

          if ($short_url) {
            $result_block = "<p><strong>Short URL:</strong> <a href='{$short_url}' target='_blank' rel='noopener'>{$short_url}</a></p>" . $result_block;
          }
        }
      }

      // ---- Render detailed steps (kept below the fold) ----
      ob_start(); ?>
      <div style="margin-top:20px;padding:16px;border:1px solid #e0e0e0;border-radius:8px;background:#f9f9f9;">
        <h3>Step 1: Inputs</h3>
        <pre><?php echo esc_html(wp_json_encode($safe_inputs, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)); ?></pre>

        <h3>Step 2: Destination URL (built)</h3>
        <p><code style="word-break:break-all;"><?php echo esc_html($dest_url); ?></code></p>

        <h3>Step 3: API Request Preview</h3>
        <p><strong>Endpoint:</strong> <code><?php echo esc_html($endpoint); ?></code></p>
        <p><strong>Headers:</strong></p>
        <pre><?php echo esc_html("Authorization: {$masked_key}\nContent-Type: application/json"); ?></pre>
        <p><strong>JSON Payload (domain + domainId, cloaking):</strong></p>
        <pre><?php echo $pretty_payload; ?></pre>
        <p><strong>cURL Example:</strong></p>
        <pre><?php echo $curl_snippet; ?></pre>

        <h3>Step 4: API Response</h3>
        <?php echo $resp_block; ?>

        <h3>Step 5: Result</h3>
        <?php echo $result_block ?: '<p>(No short URL returned.)</p>'; ?>
      </div>
      <?php
      $html = ob_get_clean();

      return [
        'html'        => $html,
        'short_url'   => $short_url,
        'dest_url'    => $dest_url,
        'qr_data_uri' => $qr_data_uri,
      ];
    }

    private function mask_key($key) {
      $len = strlen($key);
      if ($len <= 6) return str_repeat('*', $len);
      return substr($key,0,4) . str_repeat('*', max(0,$len-6)) . substr($key,-2);
    }
  }

  new SLM_Steps_Plugin();
}
add_action('plugins_loaded', 'slm_steps_bootstrap');
