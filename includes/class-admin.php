<?php
/**
 * Branded administration experience.
 *
 * @package MRN_Podcaster
 */

namespace MRN\Podcaster;

defined( 'ABSPATH' ) || exit;

/**
 * Provide the protected operational dashboard and settings.
 */
final class Admin {
	private const PAGE = 'mrn-podcaster';

	/**
	 * Create the administration controller.
	 *
	 * @param Sync_Service $sync Synchronization service.
	 */
	public function __construct( private Sync_Service $sync ) {}

	/**
	 * Register admin hooks.
	 *
	 * @return void
	 */
	public function register(): void {
		add_action( 'admin_menu', array( $this, 'menu' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'assets' ) );
		add_action( 'admin_post_mrnp_save_settings', array( $this, 'save_settings' ) );
		add_action( 'admin_post_mrnp_sync', array( $this, 'manual_sync' ) );
		add_action( 'add_meta_boxes_' . Post_Type::POST_TYPE, array( $this, 'episode_meta_box' ) );
		add_filter( 'plugin_action_links_' . plugin_basename( MRNP_FILE ), array( $this, 'plugin_links' ) );
	}

	/**
	 * Add plugin navigation.
	 *
	 * @return void
	 */
	public function menu(): void {
		$capability = 'manage_options';
		add_menu_page(
			__( 'MRN Podcaster', 'mrn-podcaster' ),
			__( 'پادکستر', 'mrn-podcaster' ),
			$capability,
			self::PAGE,
			array( $this, 'render_dashboard' ),
			'dashicons-controls-volumeon',
			20
		);
		add_submenu_page( self::PAGE, __( 'داشبورد پادکستر', 'mrn-podcaster' ), __( 'داشبورد', 'mrn-podcaster' ), $capability, self::PAGE, array( $this, 'render_dashboard' ) );
		add_submenu_page( self::PAGE, __( 'تنظیمات فید', 'mrn-podcaster' ), __( 'فید و همگام‌سازی', 'mrn-podcaster' ), $capability, self::PAGE . '-settings', array( $this, 'render_settings' ) );
		add_submenu_page( self::PAGE, __( 'شورت‌کدها', 'mrn-podcaster' ), __( 'نمایش در سایت', 'mrn-podcaster' ), $capability, self::PAGE . '-display', array( $this, 'render_display' ) );
		add_submenu_page( self::PAGE, __( 'اپیزودها', 'mrn-podcaster' ), __( 'اپیزودها', 'mrn-podcaster' ), 'edit_posts', 'edit.php?post_type=' . Post_Type::POST_TYPE );
		add_submenu_page( self::PAGE, __( 'اطلاعات پادکست', 'mrn-podcaster' ), __( 'اطلاعات پادکست', 'mrn-podcaster' ), 'edit_posts', 'edit.php?post_type=' . Post_Type::SHOW_TYPE );
	}

	/**
	 * Enqueue scoped dashboard assets.
	 *
	 * @param string $hook Hook.
	 * @return void
	 */
	public function assets( string $hook ): void {
		$screen = get_current_screen();
		if ( ! str_contains( $hook, self::PAGE ) && ( ! $screen || Post_Type::POST_TYPE !== $screen->post_type ) ) {
			return;
		}
		wp_enqueue_style( 'mrnp-admin', MRNP_URL . 'assets/css/admin.css', array(), MRNP_VERSION );
		wp_enqueue_script( 'mrnp-admin', MRNP_URL . 'assets/js/admin.js', array(), MRNP_VERSION, true );
	}

	/**
	 * Direct settings link.
	 *
	 * @param string[] $links Links.
	 * @return string[]
	 */
	public function plugin_links( array $links ): array {
		array_unshift( $links, '<a href="' . esc_url( admin_url( 'admin.php?page=' . self::PAGE ) ) . '">' . esc_html__( 'داشبورد', 'mrn-podcaster' ) . '</a>' );
		return $links;
	}

	/**
	 * Dashboard health and activity.
	 *
	 * @return void
	 */
	public function render_dashboard(): void {
		$this->authorize();
		$counts    = wp_count_posts( Post_Type::POST_TYPE );
		$published = (int) ( $counts->publish ?? 0 );
		$pending   = (int) get_comments(
			array(
				'post_type'  => array( Post_Type::POST_TYPE, Post_Type::SHOW_TYPE ),
				'type'       => 'comment',
				'status'     => 'hold',
				'count'      => true,
				'meta_query' => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- Required to isolate imported comments.
					array(
						'key'     => '_mrnp_external_hash',
						'compare' => 'EXISTS',
					),
				),
			)
		);
		$last_sync = (int) get_option( 'mrnp_last_sync', 0 );
		$next_sync = wp_next_scheduled( Scheduler::HOOK );
		$info      = (array) get_option( 'mrnp_podcast_info', array() );
		$primary   = (array) ( $info['primary'] ?? array() );
		$logs      = $this->logs();
		$this->header( 'dashboard' );
		$this->notice();
		?>
		<main class="mrnp-admin__content">
			<section class="mrnp-admin__stats">
				<?php $this->stat( __( 'اپیزود منتشرشده', 'mrn-podcaster' ), $published, __( 'آرشیو محلی و قابل ویرایش', 'mrn-podcaster' ), '🎙' ); ?>
				<?php $this->stat( __( 'نظر در انتظار', 'mrn-podcaster' ), $pending, __( 'نیازمند تأیید مدیر', 'mrn-podcaster' ), '💬' ); ?>
				<?php $this->stat( __( 'آخرین همگام‌سازی', 'mrn-podcaster' ), $last_sync ? human_time_diff( $last_sync, time() ) : '—', $last_sync ? __( 'پیش', 'mrn-podcaster' ) : __( 'هنوز اجرا نشده', 'mrn-podcaster' ), '↻' ); ?>
				<?php $this->stat( __( 'اجرای بعدی', 'mrn-podcaster' ), $next_sync ? human_time_diff( time(), $next_sync ) : '—', __( 'با WP-Cron', 'mrn-podcaster' ), '◷' ); ?>
			</section>

			<section class="mrnp-admin__grid">
				<article class="mrnp-panel mrnp-show-card">
					<div class="mrnp-panel__head">
						<div><span><?php esc_html_e( 'فید اصلی', 'mrn-podcaster' ); ?></span><h2><?php echo esc_html( $primary['title'] ?? __( 'پادکست هنوز شناسایی نشده', 'mrn-podcaster' ) ); ?></h2></div>
						<?php
						if ( $primary ) :
							?>
							<span class="mrnp-status mrnp-status--ok"><?php esc_html_e( 'متصل', 'mrn-podcaster' ); ?></span>
							<?php
else :
	?>
							<span class="mrnp-status"><?php esc_html_e( 'نیازمند تنظیم', 'mrn-podcaster' ); ?></span><?php endif; ?>
					</div>
					<div class="mrnp-show-card__body">
						<?php
						if ( ! empty( $primary['image'] ) ) :
							?>
							<img src="<?php echo esc_url( $primary['image'] ); ?>" width="132" height="132" alt=""><?php endif; ?>
						<div>
							<p><?php echo esc_html( wp_trim_words( wp_strip_all_tags( (string) ( $primary['description'] ?? '' ) ), 34 ) ); ?></p>
							<dl>
								<div><dt><?php esc_html_e( 'سازنده', 'mrn-podcaster' ); ?></dt><dd><?php echo esc_html( $primary['author'] ?? '—' ); ?></dd></div>
								<div><dt><?php esc_html_e( 'زبان', 'mrn-podcaster' ); ?></dt><dd><?php echo esc_html( $primary['language'] ?? '—' ); ?></dd></div>
							</dl>
						</div>
					</div>
					<div class="mrnp-show-card__actions">
						<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
							<input type="hidden" name="action" value="mrnp_sync"><?php wp_nonce_field( 'mrnp_sync' ); ?>
							<button class="mrnp-button mrnp-button--primary" type="submit"><span class="dashicons dashicons-update"></span><?php esc_html_e( 'همگام‌سازی اکنون', 'mrn-podcaster' ); ?></button>
						</form>
						<a class="mrnp-button" href="<?php echo esc_url( admin_url( 'admin.php?page=' . self::PAGE . '-settings' ) ); ?>"><?php esc_html_e( 'تنظیم فیدها', 'mrn-podcaster' ); ?></a>
					</div>
				</article>

				<aside class="mrnp-panel mrnp-health">
					<span class="mrnp-kicker"><?php esc_html_e( 'سلامت سامانه', 'mrn-podcaster' ); ?></span>
					<h2><?php echo $primary ? esc_html__( 'همه‌چیز آمادهٔ پخش است', 'mrn-podcaster' ) : esc_html__( 'فید اصلی را متصل کنید', 'mrn-podcaster' ); ?></h2>
					<ul>
						<li class="<?php echo Settings::get( 'primary_feed_url' ) ? 'is-ok' : ''; ?>"><i></i><span><b><?php esc_html_e( 'فید اصلی', 'mrn-podcaster' ); ?></b><small><?php esc_html_e( 'مرجع هویت اپیزودها', 'mrn-podcaster' ); ?></small></span></li>
						<li class="<?php echo Settings::get( 'backup_feed_url' ) ? 'is-ok' : ''; ?>"><i></i><span><b><?php esc_html_e( 'فید پشتیبان', 'mrn-podcaster' ); ?></b><small><?php esc_html_e( 'تعویض خودکار منبع پلیر', 'mrn-podcaster' ); ?></small></span></li>
						<li class="<?php echo $next_sync ? 'is-ok' : ''; ?>"><i></i><span><b><?php esc_html_e( 'زمان‌بندی', 'mrn-podcaster' ); ?></b><small><?php esc_html_e( 'قفل اجرای هم‌زمان فعال است', 'mrn-podcaster' ); ?></small></span></li>
						<li class="<?php echo Settings::get( 'download_audio' ) ? 'is-ok' : ''; ?>"><i></i><span><b><?php esc_html_e( 'آرشیو صوت محلی', 'mrn-podcaster' ); ?></b><small><?php echo Settings::get( 'download_audio' ) ? esc_html__( 'فعال', 'mrn-podcaster' ) : esc_html__( 'اختیاری و غیرفعال', 'mrn-podcaster' ); ?></small></span></li>
					</ul>
				</aside>
			</section>

			<section class="mrnp-panel mrnp-log">
				<div class="mrnp-panel__head"><div><span><?php esc_html_e( 'عملیات', 'mrn-podcaster' ); ?></span><h2><?php esc_html_e( 'تاریخچه همگام‌سازی', 'mrn-podcaster' ); ?></h2></div></div>
				<div class="mrnp-table-wrap"><table><thead><tr><th><?php esc_html_e( 'زمان', 'mrn-podcaster' ); ?></th><th><?php esc_html_e( 'وضعیت', 'mrn-podcaster' ); ?></th><th><?php esc_html_e( 'محرک', 'mrn-podcaster' ); ?></th><th><?php esc_html_e( 'یافت‌شده', 'mrn-podcaster' ); ?></th><th><?php esc_html_e( 'جدید/به‌روز', 'mrn-podcaster' ); ?></th><th><?php esc_html_e( 'نظر', 'mrn-podcaster' ); ?></th><th><?php esc_html_e( 'پیام', 'mrn-podcaster' ); ?></th></tr></thead>
				<tbody>
				<?php
				if ( ! $logs ) :
					?>
					<tr><td colspan="7"><?php esc_html_e( 'هنوز گزارشی ثبت نشده است.', 'mrn-podcaster' ); ?></td></tr><?php endif; ?>
				<?php
				foreach ( $logs as $log ) :
					?>
					<tr><td><?php echo esc_html( get_date_from_gmt( $log->started_at ) ); ?></td><td><span class="mrnp-status <?php echo 'success' === $log->status ? 'mrnp-status--ok' : ''; ?>"><?php echo esc_html( $log->status ); ?></span></td><td><?php echo esc_html( $log->triggered_by ); ?></td><td><?php echo esc_html( $log->episodes_found ); ?></td><td><?php echo esc_html( $log->episodes_created . ' / ' . $log->episodes_updated ); ?></td><td><?php echo esc_html( $log->comments_created ); ?></td><td title="<?php echo esc_attr( $log->message ); ?>"><?php echo esc_html( wp_trim_words( $log->message, 10 ) ); ?></td></tr><?php endforeach; ?></tbody></table></div>
			</section>
		</main>
		<?php $this->footer(); ?>
		<?php
	}

	/**
	 * Feed and runtime settings.
	 *
	 * @return void
	 */
	public function render_settings(): void {
		$this->authorize();
		$settings = Settings::all();
		$this->header( 'settings' );
		$this->notice();
		?>
		<main class="mrnp-admin__content">
			<form class="mrnp-settings" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="mrnp_save_settings"><?php wp_nonce_field( 'mrnp_save_settings' ); ?>
				<section class="mrnp-panel">
					<div class="mrnp-panel__head"><div><span><?php esc_html_e( 'منابع محتوا', 'mrn-podcaster' ); ?></span><h2><?php esc_html_e( 'فید اصلی و پشتیبان', 'mrn-podcaster' ); ?></h2><p><?php esc_html_e( 'فید اصلی هویت و ترتیب اپیزودها را تعیین می‌کند. فید پشتیبان فقط صوت و دادهٔ تکمیلی را غنی می‌کند.', 'mrn-podcaster' ); ?></p></div></div>
					<div class="mrnp-field-grid">
						<label class="mrnp-field"><span><?php esc_html_e( 'نشانی فید اصلی', 'mrn-podcaster' ); ?> <b>*</b></span><input type="url" name="settings[primary_feed_url]" value="<?php echo esc_attr( $settings['primary_feed_url'] ); ?>" placeholder="https://example.com/feed/podcast/" required dir="ltr"><small><?php esc_html_e( 'RSS یا Atom؛ تنها مرجع ایجاد اپیزود جدید.', 'mrn-podcaster' ); ?></small></label>
						<label class="mrnp-field"><span><?php esc_html_e( 'نشانی فید پشتیبان', 'mrn-podcaster' ); ?></span><input type="url" name="settings[backup_feed_url]" value="<?php echo esc_attr( $settings['backup_feed_url'] ); ?>" placeholder="https://cdn.example.com/podcast.xml" dir="ltr"><small><?php esc_html_e( 'اختیاری؛ بر اساس GUID، شماره اپیزود و عنوان تطبیق می‌یابد.', 'mrn-podcaster' ); ?></small></label>
					</div>
				</section>

				<section class="mrnp-panel">
					<div class="mrnp-panel__head"><div><span><?php esc_html_e( 'گردآوری نظرات', 'mrn-podcaster' ); ?></span><h2><?php esc_html_e( 'پلتفرم‌های انتشار', 'mrn-podcaster' ); ?></h2><p><?php esc_html_e( 'آدرس صفحات فعال را وارد کنید. آداپتورهای پلتفرم با فیلتر mrnp_external_comments می‌توانند از این فهرست استفاده کنند.', 'mrn-podcaster' ); ?></p></div><button class="mrnp-button" type="button" data-mrnp-add-platform><span class="dashicons dashicons-plus-alt2"></span><?php esc_html_e( 'افزودن پلتفرم', 'mrn-podcaster' ); ?></button></div>
					<div class="mrnp-platform-rows" data-mrnp-platforms>
						<?php
						$platforms = $settings['platforms'] ? $settings['platforms'] : array(
							array(
								'name' => '',
								'url'  => '',
							),
						);
						?>
						<?php foreach ( $platforms as $index => $platform ) : ?>
							<div class="mrnp-platform-row"><input name="settings[platforms][<?php echo esc_attr( (string) $index ); ?>][name]" value="<?php echo esc_attr( $platform['name'] ); ?>" placeholder="<?php esc_attr_e( 'نام؛ مانند Castbox', 'mrn-podcaster' ); ?>"><input type="url" dir="ltr" name="settings[platforms][<?php echo esc_attr( (string) $index ); ?>][url]" value="<?php echo esc_attr( $platform['url'] ); ?>" placeholder="https://…"><button type="button" data-mrnp-remove-platform aria-label="<?php esc_attr_e( 'حذف', 'mrn-podcaster' ); ?>">×</button></div>
						<?php endforeach; ?>
					</div>
					<template data-mrnp-platform-template><div class="mrnp-platform-row"><input name="settings[platforms][__INDEX__][name]" placeholder="<?php esc_attr_e( 'نام پلتفرم', 'mrn-podcaster' ); ?>"><input type="url" dir="ltr" name="settings[platforms][__INDEX__][url]" placeholder="https://…"><button type="button" data-mrnp-remove-platform aria-label="<?php esc_attr_e( 'حذف', 'mrn-podcaster' ); ?>">×</button></div></template>
				</section>

				<section class="mrnp-panel">
					<div class="mrnp-panel__head"><div><span><?php esc_html_e( 'رفتار سامانه', 'mrn-podcaster' ); ?></span><h2><?php esc_html_e( 'زمان‌بندی، ذخیره و پلیر', 'mrn-podcaster' ); ?></h2></div></div>
					<div class="mrnp-field-grid mrnp-field-grid--options">
						<label class="mrnp-field"><span><?php esc_html_e( 'دوره بررسی فید', 'mrn-podcaster' ); ?></span><select name="settings[sync_interval]"><option value="mrnp_fifteen_minutes" <?php selected( $settings['sync_interval'], 'mrnp_fifteen_minutes' ); ?>><?php esc_html_e( 'هر ۱۵ دقیقه', 'mrn-podcaster' ); ?></option><option value="hourly" <?php selected( $settings['sync_interval'], 'hourly' ); ?>><?php esc_html_e( 'هر ساعت', 'mrn-podcaster' ); ?></option><option value="twicedaily" <?php selected( $settings['sync_interval'], 'twicedaily' ); ?>><?php esc_html_e( 'روزی دو بار', 'mrn-podcaster' ); ?></option><option value="daily" <?php selected( $settings['sync_interval'], 'daily' ); ?>><?php esc_html_e( 'روزانه', 'mrn-podcaster' ); ?></option></select></label>
						<div class="mrnp-toggles">
							<?php $this->toggle( 'download_audio', __( 'ذخیره نسخه صوتی روی سرور', 'mrn-podcaster' ), __( 'فایل اصلی در Media Library آرشیو می‌شود.', 'mrn-podcaster' ), (bool) $settings['download_audio'] ); ?>
							<?php $this->toggle( 'import_comments', __( 'دریافت نظرات شنوندگان', 'mrn-podcaster' ), __( 'نظرهای جدید در انتظار تأیید مدیر می‌مانند.', 'mrn-podcaster' ), (bool) $settings['import_comments'] ); ?>
							<?php $this->toggle( 'global_player', __( 'پلیر سراسری پایین صفحه', 'mrn-podcaster' ), __( 'در تمام صفحات آمادهٔ ادامه پخش است.', 'mrn-podcaster' ), (bool) $settings['global_player'] ); ?>
							<?php $this->toggle( 'delete_on_uninstall', __( 'حذف کامل داده هنگام uninstall', 'mrn-podcaster' ), __( 'اپیزودها، نظرات، تنظیمات و گزارش‌ها حذف می‌شوند.', 'mrn-podcaster' ), (bool) $settings['delete_on_uninstall'], true ); ?>
						</div>
					</div>
				</section>
				<div class="mrnp-settings__save"><button class="mrnp-button mrnp-button--primary" type="submit"><?php esc_html_e( 'ذخیره تنظیمات', 'mrn-podcaster' ); ?></button></div>
			</form>
		</main>
		<?php $this->footer(); ?>
		<?php
	}

	/**
	 * Shortcode reference.
	 *
	 * @return void
	 */
	public function render_display(): void {
		$this->authorize();
		$this->header( 'display' );
		?>
		<main class="mrnp-admin__content">
			<section class="mrnp-panel">
				<div class="mrnp-panel__head"><div><span><?php esc_html_e( 'سازگار با هر قالب', 'mrn-podcaster' ); ?></span><h2><?php esc_html_e( 'شورت‌کدهای آماده', 'mrn-podcaster' ); ?></h2><p><?php esc_html_e( 'این کدها را در برگه، ابزارک یا template اجرا کنید.', 'mrn-podcaster' ); ?></p></div></div>
				<div class="mrnp-shortcodes">
					<?php $this->shortcode_card( '[mrnp_episode_carousel count="8"]', __( 'چرخ‌وفلک آخرین اپیزودها', 'mrn-podcaster' ) ); ?>
					<?php $this->shortcode_card( '[mrnp_listener_comments count="3"]', __( 'نظرات کشف‌شده و تأییدشده', 'mrn-podcaster' ) ); ?>
					<?php $this->shortcode_card( '[mrnp_player id="123"]', __( 'دکمه پخش یک اپیزود', 'mrn-podcaster' ) ); ?>
				</div>
			</section>
		</main>
		<?php $this->footer(); ?>
		<?php
	}

	/**
	 * Persist settings.
	 *
	 * @return void
	 */
	public function save_settings(): void {
		$this->authorize();
		check_admin_referer( 'mrnp_save_settings' );
		$input = isset( $_POST['settings'] ) && is_array( $_POST['settings'] ) ? wp_unslash( $_POST['settings'] ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Settings::sanitize handles each field.
		update_option( Settings::OPTION, Settings::sanitize( $input ) );
		wp_clear_scheduled_hook( Scheduler::HOOK );
		$this->redirect( 'settings-saved' );
	}

	/**
	 * Run a protected manual sync.
	 *
	 * @return void
	 */
	public function manual_sync(): void {
		$this->authorize();
		check_admin_referer( 'mrnp_sync' );
		$result = $this->sync->run( 'manual' );
		if ( is_wp_error( $result ) ) {
			set_transient( 'mrnp_admin_notice_' . get_current_user_id(), array( 'error', $result->get_error_message() ), MINUTE_IN_SECONDS );
		} else {
			set_transient(
				'mrnp_admin_notice_' . get_current_user_id(),
				array(
					'success',
					/* translators: 1: found episodes, 2: created, 3: updated, 4: imported comments. */
					sprintf( __( '%1$d اپیزود یافت شد؛ %2$d جدید، %3$d به‌روز و %4$d نظر کشف شد.', 'mrn-podcaster' ), $result['found'], $result['created'], $result['updated'], $result['comments'] ),
				),
				MINUTE_IN_SECONDS
			);
		}
		wp_safe_redirect( admin_url( 'admin.php?page=' . self::PAGE ) );
		exit;
	}

	/**
	 * Read-only source details on episode editor.
	 *
	 * @return void
	 */
	public function episode_meta_box(): void {
		add_meta_box(
			'mrnp-episode-source',
			__( 'داده‌های پادکست', 'mrn-podcaster' ),
			static function ( \WP_Post $post ): void {
				$rows = array(
					__( 'شناسه خارجی', 'mrn-podcaster' ) => get_post_meta( $post->ID, '_mrnp_external_id', true ),
					__( 'صوت اصلی', 'mrn-podcaster' )    => get_post_meta( $post->ID, '_mrnp_audio_primary', true ),
					__( 'صوت پشتیبان', 'mrn-podcaster' ) => get_post_meta( $post->ID, '_mrnp_audio_backup', true ),
					__( 'نسخه محلی', 'mrn-podcaster' )   => get_post_meta( $post->ID, '_mrnp_audio_local', true ),
					__( 'مدت', 'mrn-podcaster' )         => Normalizer::format_duration( (int) get_post_meta( $post->ID, '_mrnp_duration', true ) ),
				);
				echo '<dl class="mrnp-meta-box">';
				foreach ( $rows as $label => $value ) {
					echo '<div><dt>' . esc_html( $label ) . '</dt><dd dir="auto">' . esc_html( $value ? $value : '—' ) . '</dd></div>';
				}
				echo '</dl><p>' . esc_html__( 'متن، عنوان و تصویر شاخص را آزادانه ویرایش کنید؛ sync بعدی تغییرات دستی را حفظ می‌کند.', 'mrn-podcaster' ) . '</p>';
			},
			Post_Type::POST_TYPE,
			'side',
			'high'
		);
	}

	/**
	 * Admin header and tabs.
	 *
	 * @param string $active Active tab.
	 * @return void
	 */
	private function header( string $active ): void {
		$tabs = array(
			'dashboard' => array( self::PAGE, __( 'داشبورد', 'mrn-podcaster' ) ),
			'settings'  => array( self::PAGE . '-settings', __( 'فید و همگام‌سازی', 'mrn-podcaster' ) ),
			'display'   => array( self::PAGE . '-display', __( 'نمایش در سایت', 'mrn-podcaster' ) ),
		);
		?>
		<div class="wrap mrnp-admin" dir="rtl">
			<header class="mrnp-admin__hero">
				<div class="mrnp-admin__brand"><span class="mrnp-admin__mark"><i></i><i></i><i></i><i></i></span><div><small>MRN PODCASTER</small><h1><?php esc_html_e( 'استودیوی پادکست', 'mrn-podcaster' ); ?></h1><p><?php esc_html_e( 'از فید تا شنونده؛ یک جریان مستقل و قابل اعتماد', 'mrn-podcaster' ); ?></p></div></div>
				<div class="mrnp-admin__signal" aria-hidden="true">
				<?php
				for ( $i = 0; $i < 28; $i++ ) :
					?>
					<i style="--h:<?php echo esc_attr( (string) ( 12 + ( ( $i * 17 ) % 58 ) ) ); ?>%"></i><?php endfor; ?></div>
				<span class="mrnp-admin__version">v<?php echo esc_html( MRNP_VERSION ); ?></span>
			</header>
			<nav class="mrnp-admin__tabs">
			<?php
			foreach ( $tabs as $key => $tab ) :
				?>
				<a class="<?php echo $active === $key ? 'is-active' : ''; ?>" href="<?php echo esc_url( admin_url( 'admin.php?page=' . $tab[0] ) ); ?>"><?php echo esc_html( $tab[1] ); ?></a><?php endforeach; ?><a href="<?php echo esc_url( admin_url( 'edit.php?post_type=' . Post_Type::POST_TYPE ) ); ?>"><?php esc_html_e( 'اپیزودها', 'mrn-podcaster' ); ?></a></nav>
		<?php
	}

	/**
	 * Close admin wrapper.
	 *
	 * @return void
	 */
	private function footer(): void {
		?>
			<footer class="mrnp-admin__footer"><span>MRN Podcaster <b>v<?php echo esc_html( MRNP_VERSION ); ?></b></span><span><?php esc_html_e( 'فید اصلی مرجع است • ویرایش‌های مدیر محفوظ‌اند • نظرات بدون تأیید منتشر نمی‌شوند', 'mrn-podcaster' ); ?></span></footer>
		</div>
		<?php
	}

	/**
	 * Dashboard stat card.
	 *
	 * @param string     $label Label.
	 * @param int|string $value Value.
	 * @param string     $detail Detail.
	 * @param string     $icon Icon.
	 * @return void
	 */
	private function stat( string $label, $value, string $detail, string $icon ): void {
		echo '<article><i>' . esc_html( $icon ) . '</i><div><span>' . esc_html( $label ) . '</span><strong>' . esc_html( (string) $value ) . '</strong><small>' . esc_html( $detail ) . '</small></div></article>';
	}

	/**
	 * Option toggle.
	 *
	 * @param string $key Key.
	 * @param string $label Label.
	 * @param string $detail Detail.
	 * @param bool   $checked Checked.
	 * @param bool   $danger Danger style.
	 * @return void
	 */
	private function toggle( string $key, string $label, string $detail, bool $checked, bool $danger = false ): void {
		?>
		<label class="mrnp-toggle<?php echo $danger ? ' mrnp-toggle--danger' : ''; ?>"><input type="checkbox" name="settings[<?php echo esc_attr( $key ); ?>]" value="1" <?php checked( $checked ); ?>><i></i><span><b><?php echo esc_html( $label ); ?></b><small><?php echo esc_html( $detail ); ?></small></span></label>
		<?php
	}

	/**
	 * Shortcode card.
	 *
	 * @param string $code Code.
	 * @param string $label Label.
	 * @return void
	 */
	private function shortcode_card( string $code, string $label ): void {
		echo '<article><span>' . esc_html( $label ) . '</span><code dir="ltr">' . esc_html( $code ) . '</code><button class="mrnp-button" type="button" data-mrnp-copy="' . esc_attr( $code ) . '">' . esc_html__( 'کپی', 'mrn-podcaster' ) . '</button></article>';
	}

	/**
	 * Recent logs.
	 *
	 * @return array<int, object>
	 */
	private function logs(): array {
		global $wpdb;
		$table = $wpdb->prefix . 'mrnp_sync_log';
		return (array) $wpdb->get_results( "SELECT * FROM {$table} ORDER BY id DESC LIMIT 12" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Dedicated operational log table.
	}

	/**
	 * Show transient action notice.
	 *
	 * @return void
	 */
	private function notice(): void {
		$notice = get_transient( 'mrnp_admin_notice_' . get_current_user_id() );
		if ( $notice ) {
			delete_transient( 'mrnp_admin_notice_' . get_current_user_id() );
			echo '<div class="notice notice-' . esc_attr( $notice[0] ) . ' is-dismissible"><p>' . esc_html( $notice[1] ) . '</p></div>';
		}
		if ( isset( $_GET['mrnp-notice'] ) && 'settings-saved' === sanitize_key( wp_unslash( $_GET['mrnp-notice'] ) ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only display key after a protected redirect.
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'تنظیمات ذخیره شد. زمان‌بندی در بارگذاری بعدی تنظیم می‌شود.', 'mrn-podcaster' ) . '</p></div>';
		}
	}

	/**
	 * Protected redirect.
	 *
	 * @param string $notice Notice key.
	 * @return void
	 */
	private function redirect( string $notice ): void {
		wp_safe_redirect( add_query_arg( 'mrnp-notice', $notice, admin_url( 'admin.php?page=' . self::PAGE . '-settings' ) ) );
		exit;
	}

	/**
	 * Verify admin capability.
	 *
	 * @return void
	 */
	private function authorize(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'شما اجازه مدیریت پادکستر را ندارید.', 'mrn-podcaster' ) );
		}
	}
}
