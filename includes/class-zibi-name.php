<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Zibi_Name {
	const OPTION_KEY       = 'zibi_name_options';
	const CRON_HOOK        = 'zibi_name_daily_event';
	const USER_META_KEY    = '_zibi_name_user';
	const COMMENT_META_KEY = '_zibi_name_comment';
	const POST_META_KEY    = '_zibi_name_forum_post';
	const DATE_META_KEY    = '_zibi_name_date';
	const AI_SOURCE_META_KEY   = '_zibi_name_ai_source';
	const AI_PROVIDER_META_KEY = '_zibi_name_ai_provider';
	const AI_MODEL_META_KEY    = '_zibi_name_ai_model';
	const UPDATE_CACHE_KEY = 'zibi_name_update_cache';
	const LOG_OPTION_KEY   = 'zibi_name_activity_logs';
	const MENU_SLUG        = 'camflow';
	const PLUGIN_SLUG      = 'camflow';
	const PLUGIN_DIRNAME   = 'CamFlow';
	const PLUGIN_FILENAME  = 'CamFlow.php';
	const UPDATE_REPOSITORY = 'csyqlz/CamFlow';
	const UPDATE_ASSET_NAME = 'CamFlow.zip';
	const UPDATE_REPOSITORY_URL = 'https://github.com/csyqlz/CamFlow';
	const OFFICIAL_SITE_URL = 'https://www.camwt.com';
	const MAX_USER_POOL_SIZE = 3000;
	const MAX_ACTIVITY_LOGS = 120;
	const DEFAULT_USER_DISPLAY_LIMIT = 200;
	const STATS_CACHE_KEY = 'zibi_name_stats_cache';
	const STATS_CACHE_DURATION = 300;

	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'register_menu' ) );
		add_action( 'admin_init', array( __CLASS__, 'register_settings' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue_admin_assets' ) );
		add_action( 'wp_ajax_zibi_name_fetch_ai_models', array( __CLASS__, 'ajax_fetch_ai_models' ) );
		add_action( 'wp_ajax_zibi_name_test_ai_connection', array( __CLASS__, 'ajax_test_ai_connection' ) );
		add_action( self::CRON_HOOK, array( __CLASS__, 'run_daily_activity' ) );
		add_filter( 'plugin_action_links_' . plugin_basename( ZIBI_NAME_FILE ), array( __CLASS__, 'settings_link' ) );
		add_filter( 'pre_set_site_transient_update_plugins', array( __CLASS__, 'inject_plugin_update' ) );
		add_filter( 'plugins_api', array( __CLASS__, 'plugin_info' ), 20, 3 );
		add_filter( 'upgrader_source_selection', array( __CLASS__, 'normalize_update_source' ), 10, 4 );
	}

	public static function activate() {
		global $wpdb;

		$wpdb->query(
			"CREATE INDEX IF NOT EXISTS idx_zibi_name_comment
			ON {$wpdb->commentmeta} (meta_key(50), comment_id)"
		);

		$wpdb->query(
			"CREATE INDEX IF NOT EXISTS idx_zibi_name_user
			ON {$wpdb->usermeta} (meta_key(50), user_id)"
		);

		$wpdb->query(
			"CREATE INDEX IF NOT EXISTS idx_zibi_name_post
			ON {$wpdb->postmeta} (meta_key(50), post_id)"
		);

		self::reschedule_event( self::get_options() );
	}

	public static function deactivate() {
		self::clear_scheduled_event();
	}

	public static function uninstall() {
		self::clear_scheduled_event();
		self::delete_generated_comments();
		self::delete_generated_forum_posts();
		self::delete_generated_users();
		delete_option( self::OPTION_KEY );
		delete_option( self::LOG_OPTION_KEY );
		delete_transient( self::UPDATE_CACHE_KEY );
		delete_transient( self::STATS_CACHE_KEY );
	}

	public static function defaults() {
		return array(
			'safety_confirmed'        => 0,
			'enabled'                 => 0,
			'daily_run_time'          => '02:30',
			'user_pool_size'          => 200,
			'user_login_prefix'       => 'flow_',
			'daily_comments'          => 10,
			'daily_forum_posts'       => 2,
			'post_pool_size'          => 80,
			'comment_status'          => 'hold',
			'target_post'             => 1,
			'target_forum_post'       => 1,
			'create_forum_posts'      => 1,
			'comment_style'           => 'mixed',
			'use_ai'                  => 1,
			'ai_provider'             => 'compatible',
			'compatible_api_base'      => '',
			'compatible_api_key'       => '',
			'compatible_model'         => 'gpt-4o-mini',
			'gemini_api_key'          => '',
			'openrouter_api_key'      => '',
			'openrouter_model'        => 'openrouter/free',
			'ai_model'                => 'gemini-2.5-flash-lite',
			'ai_timeout'              => 20,
			'ai_prompt'               => '请为当前 WP 系统写一条自然、简短、像真实读者的中文评论。围绕标题和摘要展开，不要营销话术，不要夸张，不要表情，只返回正文。',
			'fallback_comments'       => "这篇内容看完之后感觉思路挺清楚，后面可以继续展开看看。\n这个角度挺实用，尤其是中间那部分说明比较直接。\n文章信息量不错，适合评论区自然互动。\n读起来比较顺，如果再补充几个案例会更完整。\n这个话题挺适合讨论，先收藏一下后面再看。",
			'fallback_forum_titles'   => "大家最近都在折腾哪些实用工具？\n这个主题有没有更简单的实现方式？\n分享一个站内内容运营的小发现\n关于社区板块展示效果的几个问题\n有没有适合新手的配置建议？",
			'fallback_forum_contents' => "这篇帖子用于补充社区板块的内容氛围，方便观察列表、详情页和评论区的排版。\n最近整理站点内容时发现一些细节，想看看社区帖子在不同用户下的展示效果。\n这个帖子用于模拟社区讨论场景，方便检查子比主题社区板块的活跃状态和交互布局。",
		);
	}

	public static function get_options() {
		$options = get_option( self::OPTION_KEY, array() );
		$options = wp_parse_args( is_array( $options ) ? $options : array(), self::defaults() );
		if ( 'gemini-3.1-flash-lite' === ( $options['ai_model'] ?? '' ) ) {
			$options['ai_model'] = self::defaults()['ai_model'];
		}
		if ( 'camflow_' === ( $options['user_login_prefix'] ?? '' ) ) {
			$options['user_login_prefix'] = self::defaults()['user_login_prefix'];
		}

		return $options;
	}

	public static function sanitize_options( $input ) {
		$defaults = self::defaults();
		$input    = is_array( $input ) ? $input : array();
		$time     = sanitize_text_field( $input['daily_run_time'] ?? $defaults['daily_run_time'] );
		$time     = preg_match( '/^(?:[01]\d|2[0-3]):[0-5]\d$/', $time ) ? $time : $defaults['daily_run_time'];
		$prefix   = sanitize_user( $input['user_login_prefix'] ?? $defaults['user_login_prefix'], true );
		$prefix   = $prefix ?: $defaults['user_login_prefix'];
		$status   = sanitize_key( $input['comment_status'] ?? 'hold' );
		$status   = in_array( $status, array( 'hold', 'approve' ), true ) ? $status : 'hold';
		$style    = sanitize_key( $input['comment_style'] ?? 'mixed' );
		$style    = in_array( $style, array( 'mixed', 'question', 'agree', 'supplement', 'discussion' ), true ) ? $style : 'mixed';
		$provider = sanitize_key( $input['ai_provider'] ?? $defaults['ai_provider'] );
		$provider = in_array( $provider, array( 'compatible', 'gemini', 'openrouter', 'none' ), true ) ? $provider : $defaults['ai_provider'];

		$options = array(
			'safety_confirmed'        => empty( $input['safety_confirmed'] ) ? 0 : 1,
			'enabled'                 => empty( $input['enabled'] ) ? 0 : 1,
			'daily_run_time'          => $time,
			'user_pool_size'          => min( 3000, max( 1, absint( $input['user_pool_size'] ?? $defaults['user_pool_size'] ) ) ),
			'user_login_prefix'       => $prefix,
			'daily_comments'          => min( 300, max( 0, absint( $input['daily_comments'] ?? $defaults['daily_comments'] ) ) ),
			'daily_forum_posts'       => min( 100, max( 0, absint( $input['daily_forum_posts'] ?? $defaults['daily_forum_posts'] ) ) ),
			'post_pool_size'          => min( 500, max( 1, absint( $input['post_pool_size'] ?? $defaults['post_pool_size'] ) ) ),
			'comment_status'          => $status,
			'target_post'             => empty( $input['target_post'] ) ? 0 : 1,
			'target_forum_post'       => empty( $input['target_forum_post'] ) ? 0 : 1,
			'create_forum_posts'      => empty( $input['create_forum_posts'] ) ? 0 : 1,
			'comment_style'           => $style,
			'use_ai'                  => empty( $input['use_ai'] ) ? 0 : 1,
			'ai_provider'             => $provider,
			'compatible_api_base'      => esc_url_raw( $input['compatible_api_base'] ?? '' ),
			'compatible_api_key'       => sanitize_text_field( $input['compatible_api_key'] ?? '' ),
			'compatible_model'         => sanitize_text_field( $input['compatible_model'] ?? $defaults['compatible_model'] ),
			'gemini_api_key'          => sanitize_text_field( $input['gemini_api_key'] ?? '' ),
			'openrouter_api_key'      => sanitize_text_field( $input['openrouter_api_key'] ?? '' ),
			'openrouter_model'        => sanitize_text_field( $input['openrouter_model'] ?? $defaults['openrouter_model'] ),
			'ai_model'                => sanitize_text_field( $input['ai_model'] ?? $defaults['ai_model'] ),
			'ai_timeout'              => min( 60, max( 3, absint( $input['ai_timeout'] ?? $defaults['ai_timeout'] ) ) ),
			'ai_prompt'               => sanitize_textarea_field( $input['ai_prompt'] ?? $defaults['ai_prompt'] ),
			'fallback_comments'       => sanitize_textarea_field( $input['fallback_comments'] ?? $defaults['fallback_comments'] ),
			'fallback_forum_titles'   => sanitize_textarea_field( $input['fallback_forum_titles'] ?? $defaults['fallback_forum_titles'] ),
			'fallback_forum_contents' => sanitize_textarea_field( $input['fallback_forum_contents'] ?? $defaults['fallback_forum_contents'] ),
		);

		self::reschedule_event( $options );
		return $options;
	}

	public static function register_menu() {
		add_menu_page( 'CamFlow', '用户自动化', 'manage_options', self::MENU_SLUG, array( __CLASS__, 'render_page' ), 'dashicons-groups', 58 );
	}

	public static function register_settings() {
		register_setting(
			'zibi_name_settings',
			self::OPTION_KEY,
			array(
				'type'              => 'array',
				'sanitize_callback' => array( __CLASS__, 'sanitize_options' ),
				'default'           => self::defaults(),
			)
		);
	}

	public static function enqueue_admin_assets( $hook ) {
		if ( 'toplevel_page_' . self::MENU_SLUG !== $hook ) {
			return;
		}

		wp_enqueue_style( 'zibi-name-admin', ZIBI_NAME_URL . 'assets/css/admin.css', array(), ZIBI_NAME_VERSION );
		wp_enqueue_script( 'zibi-name-admin', ZIBI_NAME_URL . 'assets/js/admin.js', array(), ZIBI_NAME_VERSION, true );
		wp_localize_script(
			'zibi-name-admin',
			'CamFlowAi',
			array(
				'ajaxUrl' => admin_url( 'admin-ajax.php' ),
				'nonce'   => wp_create_nonce( 'zibi_name_ai_tools' ),
			)
		);
	}

	public static function settings_link( $links ) {
		array_unshift( $links, '<a href="' . esc_url( admin_url( 'admin.php?page=' . self::MENU_SLUG ) ) . '">设置</a>' );
		$links[] = '<a href="' . esc_url( self::OFFICIAL_SITE_URL ) . '" target="_blank" rel="noopener">CAMWT官网</a>';
		return $links;
	}

	public static function render_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( '你没有权限访问此页面。' );
		}

		self::handle_action();
		$options = self::get_options();
		$tab     = sanitize_key( $_GET['tab'] ?? 'overview' );
		$tabs    = self::tabs();

		if ( ! isset( $tabs[ $tab ] ) ) {
			$tab = 'overview';
		}

		settings_errors( self::OPTION_KEY );
		?>
		<div class="wrap zibi-name-wrap">
			<div class="zibi-name-header">
				<div>
					<h1>CamFlow</h1>
					<p class="zibi-name-desc">面向 WP 系统的内容互动自动化工具：维护用户池、评论互动、社区发帖、AI 文案和运行日志。</p>
				</div>
				<div class="zibi-name-header-actions">
					<a class="button button-secondary zibi-name-site-link" href="<?php echo esc_url( self::OFFICIAL_SITE_URL ); ?>" target="_blank" rel="noopener">CAMWT官网</a>
					<div class="zibi-name-version">v<?php echo esc_html( ZIBI_NAME_VERSION ); ?></div>
				</div>
			</div>
			<nav class="nav-tab-wrapper zibi-name-tabs">
				<?php foreach ( $tabs as $key => $label ) : ?>
					<a class="nav-tab <?php echo $key === $tab ? 'nav-tab-active' : ''; ?>" href="<?php echo esc_url( admin_url( 'admin.php?page=' . self::MENU_SLUG . '&tab=' . $key ) ); ?>" data-zibi-tab="<?php echo esc_attr( $key ); ?>" aria-selected="<?php echo $key === $tab ? 'true' : 'false'; ?>"><?php echo esc_html( $label ); ?></a>
				<?php endforeach; ?>
			</nav>
			<div class="zibi-name-tab-panels">
				<?php foreach ( $tabs as $key => $label ) : ?>
					<section class="zibi-name-tab-panel <?php echo $key === $tab ? 'is-active' : ''; ?>" data-zibi-panel="<?php echo esc_attr( $key ); ?>">
						<?php self::render_tab_content( $key, $options ); ?>
					</section>
				<?php endforeach; ?>
			</div>
		</div>
		<?php
	}

	private static function render_tab_content( $tab, array $options ) {
		if ( 'overview' === $tab ) {
			self::render_overview( $options );
		} elseif ( 'schedule' === $tab ) {
			self::render_schedule_settings( $options );
		} elseif ( 'user-generator' === $tab ) {
			self::render_user_generation_settings( $options );
		} elseif ( 'comment-generator' === $tab ) {
			self::render_comment_generation_settings( $options );
		} elseif ( 'forum-generator' === $tab ) {
			self::render_forum_generation_settings( $options );
		} elseif ( 'users' === $tab ) {
			self::render_users();
		} elseif ( 'comments' === $tab ) {
			self::render_comments();
		} elseif ( 'forum' === $tab ) {
			self::render_forum_posts();
		} elseif ( 'logs' === $tab ) {
			self::render_logs();
		} elseif ( 'ai' === $tab ) {
			self::render_ai( $options );
		} elseif ( 'updates' === $tab ) {
			self::render_updates( $options );
		}
	}

	private static function tabs() {
		return array(
			'overview'          => '总览',
			'schedule'          => '计划任务',
			'user-generator'    => '用户池',
			'comment-generator' => '评论互动',
			'forum-generator'   => '社区发帖',
			'users'             => '用户管理',
			'comments'          => '评论管理',
			'forum'             => '帖子管理',
			'logs'              => '运行日志',
			'ai'                => 'AI 接入',
			'updates'           => '插件更新',
		);
	}

	private static function render_overview( array $options ) {
		$stats = self::stats();
		?>
		<div class="zibi-name-stats">
			<?php self::stat_card( '用户池', $stats['users'] ); ?>
			<?php self::stat_card( '文章评论', $stats['post_comments'] ); ?>
			<?php self::stat_card( '社区评论', $stats['forum_comments'] ); ?>
			<?php self::stat_card( '社区帖子', $stats['forum_posts'] ); ?>
			<?php self::stat_card( '今日生成', $stats['today_total'] ); ?>
			<?php self::stat_card( '失败记录', $stats['failures'] ); ?>
			<?php self::stat_card( '下次运行', self::next_run_text(), true ); ?>
		</div>
		<div class="zibi-name-dashboard">
			<div class="zibi-name-panel">
				<h2>快速操作</h2>
				<div class="zibi-name-actions">
					<?php self::action_button( '补齐 200 用户池', 'ensure_users', 'secondary' ); ?>
					<?php self::action_button( '立即生成评论', 'generate_comments', 'primary' ); ?>
					<?php self::action_button( '立即生成社区帖', 'generate_forum_posts', 'primary' ); ?>
				</div>
				<p class="description">自动任务只有在“站点确认”和“每日自动运行”同时开启后才会执行。当前运行时间：<?php echo esc_html( $options['daily_run_time'] ); ?></p>
			</div>
			<div class="zibi-name-panel">
				<h2>完善建议</h2>
				<ul class="zibi-name-checklist">
					<li>先进入“AI 接入”填写可用的云端接口，失败时仍会使用备用模板。</li>
					<li>评论建议先保持“待审核”，确认内容风格后再改为直接发布。</li>
					<li>若评论或发帖数量异常，先看“运行日志”里的失败原因。</li>
					<li>后续可加内容去重和关键词屏蔽，避免多次生成相近评论。</li>
					<li>管理列表可继续升级为分页、搜索、按状态筛选和批量导出。</li>
				</ul>
			</div>
		</div>
		<?php
	}

	private static function render_schedule_settings( array $options ) {
		?>
		<form method="post" action="options.php" class="zibi-name-panel">
			<?php settings_fields( 'zibi_name_settings' ); ?>
			<?php self::hidden_option_fields( $options, array( 'safety_confirmed', 'enabled', 'daily_run_time' ) ); ?>
			<h2>安全与定时</h2>
			<table class="form-table" role="presentation">
				<tr><th scope="row">站点确认</th><td><label><input type="checkbox" name="<?php echo esc_attr( self::OPTION_KEY ); ?>[safety_confirmed]" value="1" <?php checked( $options['safety_confirmed'], 1 ); ?>> 我确认当前站点允许执行自动化内容互动</label></td></tr>
				<tr><th scope="row">每日自动运行</th><td><label><input type="checkbox" name="<?php echo esc_attr( self::OPTION_KEY ); ?>[enabled]" value="1" <?php checked( $options['enabled'], 1 ); ?>> 启用每日定时任务</label></td></tr>
				<tr><th scope="row">每日运行时间</th><td><input type="time" name="<?php echo esc_attr( self::OPTION_KEY ); ?>[daily_run_time]" value="<?php echo esc_attr( $options['daily_run_time'] ); ?>"><p class="description">按 WordPress 站点时区计算。保存后会重新安排下一次任务。当前下次运行：<?php echo esc_html( self::next_run_text() ); ?></p></td></tr>
			</table>
			<?php submit_button( '保存定时设置' ); ?>
		</form>
		<div class="zibi-name-panel">
			<h2>定时任务操作</h2>
			<div class="zibi-name-actions"><?php self::action_button( '立即重新安排定时任务', 'reschedule_cron', 'secondary' ); ?><?php self::action_button( '立即执行一次每日任务', 'run_daily_now', 'primary' ); ?></div>
			<p class="description">“立即执行一次每日任务”会按当前数量生成用户、评论和社区帖，不必等到下一次计划时间。</p>
		</div>
		<?php
	}

	private static function render_user_generation_settings( array $options ) {
		?>
		<form method="post" action="options.php" class="zibi-name-panel">
			<?php settings_fields( 'zibi_name_settings' ); ?>
			<?php self::hidden_option_fields( $options, array( 'user_pool_size', 'user_login_prefix' ) ); ?>
			<h2>用户生成</h2>
			<table class="form-table" role="presentation">
				<tr><th scope="row">用户池数量</th><td><input type="number" min="1" max="3000" name="<?php echo esc_attr( self::OPTION_KEY ); ?>[user_pool_size]" value="<?php echo esc_attr( $options['user_pool_size'] ); ?>"><p class="description">默认 200 个，昵称优先由 AI 批量生成，失败时使用本地抽象词库。</p></td></tr>
				<tr><th scope="row">登录名前缀</th><td><input type="text" class="regular-text" name="<?php echo esc_attr( self::OPTION_KEY ); ?>[user_login_prefix]" value="<?php echo esc_attr( $options['user_login_prefix'] ); ?>"></td></tr>
			</table>
			<?php submit_button( '保存用户生成设置' ); ?>
		</form>
		<div class="zibi-name-panel">
			<h2>用户池操作</h2>
			<div class="zibi-name-actions"><?php self::action_button( '补齐 200 用户池', 'ensure_users', 'primary' ); ?></div>
			<p class="description">当前已生成 <?php echo esc_html( count( self::get_generated_users( self::MAX_USER_POOL_SIZE ) ) ); ?> 个用户。插件只会补齐缺口，不会重复创建已有用户。</p>
		</div>
		<?php
	}

	private static function render_comment_generation_settings( array $options ) {
		?>
		<form method="post" action="options.php" class="zibi-name-panel">
			<?php settings_fields( 'zibi_name_settings' ); ?>
			<?php self::hidden_option_fields( $options, array( 'daily_comments', 'post_pool_size', 'comment_status', 'target_post', 'target_forum_post', 'comment_style' ) ); ?>
			<h2>评论生成</h2>
			<table class="form-table" role="presentation">
				<tr><th scope="row">每日评论数量</th><td><input type="number" min="0" max="300" name="<?php echo esc_attr( self::OPTION_KEY ); ?>[daily_comments]" value="<?php echo esc_attr( $options['daily_comments'] ); ?>"></td></tr>
				<tr><th scope="row">评论发布状态</th><td><select name="<?php echo esc_attr( self::OPTION_KEY ); ?>[comment_status]"><option value="hold" <?php selected( $options['comment_status'], 'hold' ); ?>>待审核</option><option value="approve" <?php selected( $options['comment_status'], 'approve' ); ?>>直接发布</option></select></td></tr>
				<tr><th scope="row">抽取范围</th><td><input type="number" min="1" max="500" name="<?php echo esc_attr( self::OPTION_KEY ); ?>[post_pool_size]" value="<?php echo esc_attr( $options['post_pool_size'] ); ?>"><p class="description">从最近发布的内容里随机抽取目标。</p></td></tr>
				<tr><th scope="row">目标内容</th><td><label><input type="checkbox" name="<?php echo esc_attr( self::OPTION_KEY ); ?>[target_post]" value="1" <?php checked( $options['target_post'], 1 ); ?>> 普通文章评论</label><label class="zibi-name-inline"><input type="checkbox" name="<?php echo esc_attr( self::OPTION_KEY ); ?>[target_forum_post]" value="1" <?php checked( $options['target_forum_post'], 1 ); ?>> 子比社区 forum_post 评论</label></td></tr>
			</table>
			<table class="form-table" role="presentation">
				<tr><th scope="row">评论风格</th><td><select name="<?php echo esc_attr( self::OPTION_KEY ); ?>[comment_style]"><option value="mixed" <?php selected( $options['comment_style'], 'mixed' ); ?>>混合随机</option><option value="question" <?php selected( $options['comment_style'], 'question' ); ?>>提问型</option><option value="agree" <?php selected( $options['comment_style'], 'agree' ); ?>>赞同型</option><option value="supplement" <?php selected( $options['comment_style'], 'supplement' ); ?>>补充观点型</option><option value="discussion" <?php selected( $options['comment_style'], 'discussion' ); ?>>轻讨论型</option></select></td></tr>
			</table>
			<?php submit_button( '保存评论生成设置' ); ?>
		</form>
		<div class="zibi-name-panel">
			<h2>评论操作</h2>
			<div class="zibi-name-actions"><?php self::action_button( '立即生成评论', 'generate_comments', 'primary' ); ?></div>
		</div>
		<?php
	}

	private static function render_forum_generation_settings( array $options ) {
		?>
		<form method="post" action="options.php" class="zibi-name-panel">
			<?php settings_fields( 'zibi_name_settings' ); ?>
			<?php self::hidden_option_fields( $options, array( 'create_forum_posts', 'daily_forum_posts' ) ); ?>
			<h2>社区帖子生成</h2>
			<table class="form-table" role="presentation">
				<tr><th scope="row">自动生成社区帖</th><td><label><input type="checkbox" name="<?php echo esc_attr( self::OPTION_KEY ); ?>[create_forum_posts]" value="1" <?php checked( $options['create_forum_posts'], 1 ); ?>> 每日任务中自动发布社区帖</label></td></tr>
				<tr><th scope="row">每日社区帖数量</th><td><input type="number" min="0" max="100" name="<?php echo esc_attr( self::OPTION_KEY ); ?>[daily_forum_posts]" value="<?php echo esc_attr( $options['daily_forum_posts'] ); ?>"></td></tr>
			</table>
			<?php submit_button( '保存社区生成设置' ); ?>
		</form>
		<div class="zibi-name-panel">
			<h2>社区操作</h2>
			<div class="zibi-name-actions"><?php self::action_button( '立即生成社区帖', 'generate_forum_posts', 'primary' ); ?></div>
		</div>
		<?php
	}

	private static function render_ai( array $options ) {
		?>
		<form method="post" action="options.php" class="zibi-name-panel" data-zibi-ai-form>
			<?php settings_fields( 'zibi_name_settings' ); ?>
			<?php self::hidden_generation_fields( $options ); ?>
			<h2>免费 AI 接入</h2>
			<p>这里接入的是云端 API，不调用本地模型。New API、sub2api、One API 等 OpenAI 兼容中转可填写接口地址、API Key 和模型名；服务商接口失败时会自动使用备用模板。</p>
			<table class="form-table" role="presentation">
				<tr><th scope="row">启用 AI</th><td><label><input type="checkbox" name="<?php echo esc_attr( self::OPTION_KEY ); ?>[use_ai]" value="1" <?php checked( $options['use_ai'], 1 ); ?>> 优先调用 AI，失败后使用备用模板</label></td></tr>
				<tr><th scope="row">AI 服务</th><td><select name="<?php echo esc_attr( self::OPTION_KEY ); ?>[ai_provider]" data-zibi-ai-provider><option value="compatible" <?php selected( $options['ai_provider'], 'compatible' ); ?>>OpenAI 兼容接口（New API / sub2api / One API）</option><option value="openrouter" <?php selected( $options['ai_provider'], 'openrouter' ); ?>>OpenRouter 免费模型</option><option value="gemini" <?php selected( $options['ai_provider'], 'gemini' ); ?>>Google Gemini API</option><option value="none" <?php selected( $options['ai_provider'], 'none' ); ?>>不使用 AI</option></select></td></tr>
				<tr class="zibi-name-ai-field is-compatible"><th scope="row">兼容接口地址</th><td><input type="url" class="regular-text" name="<?php echo esc_attr( self::OPTION_KEY ); ?>[compatible_api_base]" value="<?php echo esc_attr( $options['compatible_api_base'] ); ?>" placeholder="https://api.example.com/v1"><p class="description">New API、sub2api 这类服务通常填写 <code>https://你的域名/v1</code>；也可以填写完整 <code>/v1/chat/completions</code> 地址。</p></td></tr>
				<tr class="zibi-name-ai-field is-compatible"><th scope="row">兼容接口 Key</th><td><input type="password" class="regular-text" name="<?php echo esc_attr( self::OPTION_KEY ); ?>[compatible_api_key]" value="<?php echo esc_attr( $options['compatible_api_key'] ); ?>" autocomplete="new-password"></td></tr>
				<tr class="zibi-name-ai-field is-compatible"><th scope="row">兼容接口模型</th><td><input type="text" class="regular-text" list="camflow-model-options" name="<?php echo esc_attr( self::OPTION_KEY ); ?>[compatible_model]" value="<?php echo esc_attr( $options['compatible_model'] ); ?>" placeholder="选择或输入模型 ID" data-zibi-model-input="compatible"><p class="description">模型名以你的 New API 或 sub2api 后台可用模型为准，可手动输入，也可以点击下方按钮读取。</p></td></tr>
				<tr class="zibi-name-ai-field is-openrouter"><th scope="row">OpenRouter API Key</th><td><input type="password" class="regular-text" name="<?php echo esc_attr( self::OPTION_KEY ); ?>[openrouter_api_key]" value="<?php echo esc_attr( $options['openrouter_api_key'] ); ?>" autocomplete="new-password"></td></tr>
				<tr class="zibi-name-ai-field is-openrouter"><th scope="row">OpenRouter 模型</th><td><input type="text" class="regular-text" list="camflow-model-options" name="<?php echo esc_attr( self::OPTION_KEY ); ?>[openrouter_model]" value="<?php echo esc_attr( $options['openrouter_model'] ); ?>" placeholder="例如填写一个 :free 模型 ID" data-zibi-model-input="openrouter"><p class="description">可填写 <code>openrouter/free</code> 或 OpenRouter 上带 <code>:free</code> 后缀的模型 ID。</p></td></tr>
				<tr class="zibi-name-ai-field is-gemini"><th scope="row">Gemini API Key</th><td><input type="password" class="regular-text" name="<?php echo esc_attr( self::OPTION_KEY ); ?>[gemini_api_key]" value="<?php echo esc_attr( $options['gemini_api_key'] ); ?>" autocomplete="new-password"><p class="description">在 Google AI Studio 创建 API Key 后填写。</p></td></tr>
				<tr class="zibi-name-ai-field is-gemini"><th scope="row">Gemini 模型</th><td><input type="text" class="regular-text" list="camflow-model-options" name="<?php echo esc_attr( self::OPTION_KEY ); ?>[ai_model]" value="<?php echo esc_attr( $options['ai_model'] ); ?>" data-zibi-model-input="gemini"><p class="description">默认：gemini-2.5-flash-lite，可改成 Google AI Studio 中可用的模型 ID。</p></td></tr>
				<tr><th scope="row">超时时间</th><td><input type="number" min="3" max="60" name="<?php echo esc_attr( self::OPTION_KEY ); ?>[ai_timeout]" value="<?php echo esc_attr( $options['ai_timeout'] ); ?>"> 秒</td></tr>
				<tr><th scope="row">AI 提示词</th><td><textarea class="large-text" rows="4" name="<?php echo esc_attr( self::OPTION_KEY ); ?>[ai_prompt]"><?php echo esc_textarea( $options['ai_prompt'] ); ?></textarea></td></tr>
				<tr><th scope="row">备用评论模板</th><td><textarea class="large-text" rows="6" name="<?php echo esc_attr( self::OPTION_KEY ); ?>[fallback_comments]"><?php echo esc_textarea( $options['fallback_comments'] ); ?></textarea></td></tr>
				<tr><th scope="row">备用社区帖标题</th><td><textarea class="large-text" rows="5" name="<?php echo esc_attr( self::OPTION_KEY ); ?>[fallback_forum_titles]"><?php echo esc_textarea( $options['fallback_forum_titles'] ); ?></textarea></td></tr>
				<tr><th scope="row">备用社区帖正文</th><td><textarea class="large-text" rows="5" name="<?php echo esc_attr( self::OPTION_KEY ); ?>[fallback_forum_contents]"><?php echo esc_textarea( $options['fallback_forum_contents'] ); ?></textarea></td></tr>
			</table>
			<datalist id="camflow-model-options">
				<option value="gpt-4o-mini">
				<option value="deepseek-chat">
				<option value="deepseek-reasoner">
				<option value="qwen-plus">
				<option value="qwen-turbo">
				<option value="glm-4-flash">
				<option value="openrouter/free">
				<option value="gemini-2.5-flash-lite">
			</datalist>
			<div class="zibi-name-ai-tools" data-zibi-ai-tools>
				<button type="button" class="button" data-zibi-fetch-models>获取模型列表</button>
				<button type="button" class="button button-secondary" data-zibi-test-ai>测试连接</button>
				<span class="zibi-name-ai-status" data-zibi-ai-status aria-live="polite"></span>
				<div class="zibi-name-model-list" data-zibi-model-list hidden></div>
			</div>
			<?php submit_button( '保存 AI 设置' ); ?>
		</form>
		<?php
	}

	private static function render_updates( array $options ) {
		$cache = get_transient( self::UPDATE_CACHE_KEY );
		$latest_version = is_array( $cache ) && ! empty( $cache['version'] ) ? $cache['version'] : '';
		$has_update = $latest_version && version_compare( $latest_version, ZIBI_NAME_VERSION, '>' );
		?>
		<div class="zibi-name-panel">
			<h2>插件更新</h2>
			<table class="form-table zibi-name-update-table" role="presentation">
				<tr><th scope="row">当前版本</th><td><code><?php echo esc_html( ZIBI_NAME_VERSION ); ?></code></td></tr>
				<tr><th scope="row">最新版本</th><td><?php echo $latest_version ? '<code>' . esc_html( $latest_version ) . '</code>' : '尚未检查'; ?><?php echo $has_update ? ' <span class="zibi-name-badge is-warning">可更新</span>' : ''; ?></td></tr>
				<?php if ( is_array( $cache ) && ! empty( $cache['published_at'] ) ) : ?><tr><th scope="row">发布时间</th><td><?php echo esc_html( $cache['published_at'] ); ?></td></tr><?php endif; ?>
			</table>
			<div class="zibi-name-actions">
				<?php self::action_button( '检查 GitHub 最新版本', 'check_update', 'primary' ); ?>
				<?php self::action_button( '立即执行插件更新', 'manual_update', 'secondary', '确定从 GitHub Release 下载 CamFlow.zip 并更新插件吗？请先备份。' ); ?>
			</div>
			<?php if ( is_array( $cache ) ) : ?>
				<?php if ( ! empty( $cache['html_url'] ) ) : ?><p><a class="button button-secondary" href="<?php echo esc_url( $cache['html_url'] ); ?>" target="_blank" rel="noopener">打开 Release 页面</a></p><?php endif; ?>
				<?php if ( ! empty( $cache['download_url'] ) ) : ?><p><a class="button button-primary" href="<?php echo esc_url( $cache['download_url'] ); ?>" target="_blank" rel="noopener">下载更新包</a></p><?php endif; ?>
				<?php if ( ! empty( $cache['body'] ) ) : ?><h3>更新说明</h3><div class="zibi-name-release-notes"><?php echo wp_kses_post( wpautop( wp_trim_words( wp_strip_all_tags( $cache['body'] ), 260 ) ) ); ?></div><?php endif; ?>
			<?php else : ?>
				<p class="description">尚未检查更新。</p>
			<?php endif; ?>
		</div>
		<?php
	}

	private static function render_users() {
		$users = self::get_generated_users( self::DEFAULT_USER_DISPLAY_LIMIT );
		?>
		<div class="zibi-name-panel">
			<h2>生成用户管理</h2>
			<div class="zibi-name-actions"><?php self::action_button( '补齐用户池', 'ensure_users', 'primary' ); ?><?php self::action_button( '一键清理全部生成用户', 'delete_users', 'secondary', '确定删除插件生成的全部用户吗？建议先清理评论和社区帖。' ); ?></div>
			<form method="post">
				<?php wp_nonce_field( 'zibi_name_bulk_delete_users' ); ?>
				<input type="hidden" name="zibi_name_action" value="bulk_delete_users">
				<div class="zibi-name-table-scroll"><?php self::users_table( $users ); ?></div>
				<p><?php submit_button( '删除勾选用户', 'delete', 'submit', false, array( 'data-zibi-confirm' => '确定删除勾选用户吗？' ) ); ?></p>
			</form>
		</div>
		<?php
	}

	private static function render_comments() {
		$comments = self::get_generated_comments( self::DEFAULT_USER_DISPLAY_LIMIT );
		?>
		<div class="zibi-name-panel">
			<h2>生成评论管理</h2>
			<div class="zibi-name-actions"><?php self::action_button( '立即生成评论', 'generate_comments', 'primary' ); ?><?php self::action_button( '一键清理全部生成评论', 'delete_comments', 'secondary', '确定删除插件生成的全部评论吗？' ); ?></div>
			<form method="post">
				<?php wp_nonce_field( 'zibi_name_bulk_delete_comments' ); ?>
				<input type="hidden" name="zibi_name_action" value="bulk_delete_comments">
				<div class="zibi-name-table-scroll"><?php self::comments_table( $comments ); ?></div>
				<p><?php submit_button( '删除勾选评论', 'delete', 'submit', false, array( 'data-zibi-confirm' => '确定删除勾选评论吗？' ) ); ?></p>
			</form>
		</div>
		<?php
	}

	private static function render_forum_posts() {
		$posts = self::get_generated_forum_posts( self::DEFAULT_USER_DISPLAY_LIMIT );
		?>
		<div class="zibi-name-panel">
			<h2>生成社区帖管理</h2>
			<div class="zibi-name-actions"><?php self::action_button( '立即生成社区帖', 'generate_forum_posts', 'primary' ); ?><?php self::action_button( '一键清理全部社区帖', 'delete_forum_posts', 'secondary', '确定删除插件生成的全部社区帖吗？' ); ?></div>
			<form method="post">
				<?php wp_nonce_field( 'zibi_name_bulk_delete_forum_posts' ); ?>
				<input type="hidden" name="zibi_name_action" value="bulk_delete_forum_posts">
				<div class="zibi-name-table-scroll"><?php self::forum_posts_table( $posts ); ?></div>
				<p><?php submit_button( '删除勾选社区帖', 'delete', 'submit', false, array( 'data-zibi-confirm' => '确定删除勾选社区帖吗？' ) ); ?></p>
			</form>
		</div>
		<?php
	}

	private static function render_logs() {
		$logs = self::activity_logs();
		?>
		<div class="zibi-name-panel zibi-name-wide-panel">
			<h2>运行日志</h2>
			<div class="zibi-name-actions"><?php self::action_button( '清空运行日志', 'clear_logs', 'secondary', '确定清空运行日志吗？' ); ?></div>
			<div class="zibi-name-table-scroll"><?php self::logs_table( $logs ); ?></div>
		</div>
		<?php
	}

	private static function users_table( array $users ) {
		?>
		<table class="widefat striped zibi-name-table">
			<thead><tr><td class="check-column"><input type="checkbox" class="zibi-name-check-all"></td><th>ID</th><th>昵称</th><th>登录名</th><th>评论数</th><th>注册时间</th></tr></thead>
			<tbody>
			<?php if ( empty( $users ) ) : ?><tr><td colspan="6">暂无生成用户。</td></tr><?php endif; ?>
			<?php foreach ( $users as $user ) : ?>
				<tr><th class="check-column"><input type="checkbox" name="user_ids[]" value="<?php echo esc_attr( $user->ID ); ?>"></th><td><?php echo esc_html( $user->ID ); ?></td><td><?php echo esc_html( $user->display_name ); ?></td><td><?php echo esc_html( $user->user_login ); ?></td><td><?php echo esc_html( get_comments( array( 'user_id' => $user->ID, 'count' => true, 'status' => 'all' ) ) ); ?></td><td><?php echo esc_html( $user->user_registered ); ?></td></tr>
			<?php endforeach; ?>
			</tbody>
		</table>
		<?php
	}

	private static function comments_table( array $comments ) {
		?>
		<table class="widefat striped zibi-name-table">
			<thead><tr><td class="check-column"><input type="checkbox" class="zibi-name-check-all"></td><th>ID</th><th>作者</th><th>所属内容</th><th>类型</th><th>状态</th><th>AI 模型</th><th>内容</th></tr></thead>
			<tbody>
			<?php if ( empty( $comments ) ) : ?><tr><td colspan="8">暂无生成评论。</td></tr><?php endif; ?>
			<?php foreach ( $comments as $comment ) : ?>
				<tr><th class="check-column"><input type="checkbox" name="comment_ids[]" value="<?php echo esc_attr( $comment->comment_ID ); ?>"></th><td><?php echo esc_html( $comment->comment_ID ); ?></td><td><?php echo esc_html( $comment->comment_author ); ?></td><td><?php echo esc_html( get_the_title( $comment->comment_post_ID ) ); ?></td><td><?php echo esc_html( get_post_type( $comment->comment_post_ID ) ); ?></td><td><?php echo esc_html( wp_get_comment_status( $comment ) ); ?></td><td><?php echo esc_html( self::comment_ai_model_label( $comment->comment_ID ) ); ?></td><td><?php echo esc_html( wp_trim_words( $comment->comment_content, 18 ) ); ?></td></tr>
			<?php endforeach; ?>
			</tbody>
		</table>
		<?php
	}

	private static function forum_posts_table( array $posts ) {
		?>
		<table class="widefat striped zibi-name-table">
			<thead><tr><td class="check-column"><input type="checkbox" class="zibi-name-check-all"></td><th>ID</th><th>标题</th><th>作者</th><th>板块</th><th>评论数</th><th>发布时间</th></tr></thead>
			<tbody>
			<?php if ( empty( $posts ) ) : ?><tr><td colspan="7">暂无生成社区帖。</td></tr><?php endif; ?>
			<?php foreach ( $posts as $post ) : ?>
				<tr><th class="check-column"><input type="checkbox" name="post_ids[]" value="<?php echo esc_attr( $post->ID ); ?>"></th><td><?php echo esc_html( $post->ID ); ?></td><td><?php echo esc_html( get_the_title( $post ) ); ?></td><td><?php echo esc_html( get_the_author_meta( 'display_name', $post->post_author ) ); ?></td><td><?php echo esc_html( get_the_title( get_post_meta( $post->ID, 'plate_id', true ) ) ); ?></td><td><?php echo esc_html( get_comments_number( $post->ID ) ); ?></td><td><?php echo esc_html( $post->post_date ); ?></td></tr>
			<?php endforeach; ?>
			</tbody>
		</table>
		<?php
	}

	private static function logs_table( array $logs ) {
		?>
		<table class="widefat striped zibi-name-table zibi-name-log-table">
			<thead><tr><th>时间</th><th>类型</th><th>状态</th><th>原因 / 详情</th><th>关联信息</th></tr></thead>
			<tbody>
			<?php if ( empty( $logs ) ) : ?><tr><td colspan="5">暂无运行日志。</td></tr><?php endif; ?>
			<?php foreach ( $logs as $log ) : ?>
				<tr>
					<td><?php echo esc_html( $log['time'] ?? '' ); ?></td>
					<td><?php echo esc_html( self::activity_type_label( $log['type'] ?? '' ) ); ?></td>
					<td><span class="zibi-name-badge is-<?php echo esc_attr( $log['status'] ?? 'info' ); ?>"><?php echo esc_html( self::activity_status_label( $log['status'] ?? '' ) ); ?></span></td>
					<td><?php echo esc_html( $log['message'] ?? '' ); ?></td>
					<td><?php echo esc_html( self::format_log_context( $log['context'] ?? array() ) ); ?></td>
				</tr>
			<?php endforeach; ?>
			</tbody>
		</table>
		<?php
	}

	private static function store_comment_ai_meta( $comment_id, array $meta ) {
		$source = sanitize_key( $meta['source'] ?? 'template' );
		add_comment_meta( $comment_id, self::AI_SOURCE_META_KEY, $source, true );
		if ( ! empty( $meta['provider'] ) ) {
			add_comment_meta( $comment_id, self::AI_PROVIDER_META_KEY, sanitize_text_field( $meta['provider'] ), true );
		}
		if ( ! empty( $meta['model'] ) ) {
			add_comment_meta( $comment_id, self::AI_MODEL_META_KEY, sanitize_text_field( $meta['model'] ), true );
		}
	}

	private static function store_post_ai_meta( $post_id, array $meta ) {
		$source = sanitize_key( $meta['source'] ?? 'template' );
		update_post_meta( $post_id, self::AI_SOURCE_META_KEY, $source );
		if ( ! empty( $meta['provider'] ) ) {
			update_post_meta( $post_id, self::AI_PROVIDER_META_KEY, sanitize_text_field( $meta['provider'] ) );
		}
		if ( ! empty( $meta['model'] ) ) {
			update_post_meta( $post_id, self::AI_MODEL_META_KEY, sanitize_text_field( $meta['model'] ) );
		}
	}

	private static function comment_ai_model_label( $comment_id ) {
		$source   = get_comment_meta( $comment_id, self::AI_SOURCE_META_KEY, true );
		$provider = get_comment_meta( $comment_id, self::AI_PROVIDER_META_KEY, true );
		$model    = get_comment_meta( $comment_id, self::AI_MODEL_META_KEY, true );

		if ( 'template' === $source ) {
			return '备用模板';
		}
		if ( $model ) {
			return trim( ( $provider ? $provider . ' / ' : '' ) . $model );
		}

		return '-';
	}

	private static function hidden_generation_fields( array $options ) {
		foreach ( array( 'safety_confirmed', 'enabled', 'target_post', 'target_forum_post', 'create_forum_posts' ) as $key ) {
			if ( ! empty( $options[ $key ] ) ) {
				echo '<input type="hidden" name="' . esc_attr( self::OPTION_KEY . '[' . $key . ']' ) . '" value="1">';
			}
		}
		foreach ( array( 'daily_run_time', 'user_pool_size', 'user_login_prefix', 'daily_comments', 'daily_forum_posts', 'post_pool_size', 'comment_status', 'comment_style' ) as $key ) {
			echo '<input type="hidden" name="' . esc_attr( self::OPTION_KEY . '[' . $key . ']' ) . '" value="' . esc_attr( $options[ $key ] ) . '">';
		}
	}

	private static function hidden_option_fields( array $options, array $editable_keys ) {
		foreach ( self::option_field_keys() as $key ) {
			if ( in_array( $key, $editable_keys, true ) ) {
				continue;
			}
			echo '<input type="hidden" name="' . esc_attr( self::OPTION_KEY . '[' . $key . ']' ) . '" value="' . esc_attr( $options[ $key ] ?? '' ) . '">';
		}
	}

	private static function hidden_ai_fields( array $options ) {
		foreach ( array( 'use_ai' ) as $key ) {
			if ( ! empty( $options[ $key ] ) ) {
				echo '<input type="hidden" name="' . esc_attr( self::OPTION_KEY . '[' . $key . ']' ) . '" value="1">';
			}
		}
		foreach ( array( 'ai_provider', 'compatible_api_base', 'compatible_api_key', 'compatible_model', 'gemini_api_key', 'openrouter_api_key', 'openrouter_model', 'ai_model', 'ai_timeout', 'ai_prompt', 'fallback_comments', 'fallback_forum_titles', 'fallback_forum_contents' ) as $key ) {
			echo '<input type="hidden" name="' . esc_attr( self::OPTION_KEY . '[' . $key . ']' ) . '" value="' . esc_attr( $options[ $key ] ) . '">';
		}
	}

	private static function option_field_keys() {
		return array_keys( self::defaults() );
	}

	private static function stat_card( $label, $value, $small = false ) {
		echo '<div class="zibi-name-stat"><strong>' . esc_html( $label ) . '</strong><span class="' . ( $small ? 'is-small' : '' ) . '">' . esc_html( $value ) . '</span></div>';
	}

	private static function action_button( $label, $action, $type = 'secondary', $confirm = '' ) {
		?>
		<form method="post">
			<?php wp_nonce_field( 'zibi_name_action_' . $action ); ?>
			<input type="hidden" name="zibi_name_action" value="<?php echo esc_attr( $action ); ?>">
			<?php submit_button( $label, $type, 'submit', false, $confirm ? array( 'data-zibi-confirm' => $confirm ) : array() ); ?>
		</form>
		<?php
	}

	private static function handle_action() {
		if ( empty( $_POST['zibi_name_action'] ) ) {
			return;
		}

		$action = sanitize_key( $_POST['zibi_name_action'] );

		if ( 'bulk_delete_users' === $action ) {
			check_admin_referer( 'zibi_name_bulk_delete_users' );
			self::notice( sprintf( '已删除 %d 个勾选用户。', self::delete_users_by_ids( array_map( 'absint', $_POST['user_ids'] ?? array() ) ) ) );
			return;
		}
		if ( 'bulk_delete_comments' === $action ) {
			check_admin_referer( 'zibi_name_bulk_delete_comments' );
			self::notice( sprintf( '已删除 %d 条勾选评论。', self::delete_comments_by_ids( array_map( 'absint', $_POST['comment_ids'] ?? array() ) ) ) );
			return;
		}
		if ( 'bulk_delete_forum_posts' === $action ) {
			check_admin_referer( 'zibi_name_bulk_delete_forum_posts' );
			self::notice( sprintf( '已删除 %d 篇勾选社区帖。', self::delete_posts_by_ids( array_map( 'absint', $_POST['post_ids'] ?? array() ) ) ) );
			return;
		}

		check_admin_referer( 'zibi_name_action_' . $action );
		$options = self::get_options();

		if ( 'ensure_users' === $action ) {
			self::notice( sprintf( '已补齐用户池，本次新增 %d 个用户。', self::ensure_users( $options ) ) );
		} elseif ( 'generate_comments' === $action ) {
			$before  = self::activity_log_count( 'error' );
			$created = self::generate_comments( $options );
			$failed  = self::activity_log_count( 'error' ) - $before;
			self::notice( sprintf( '已生成 %d 条评论%s。', $created, $failed > 0 ? '，新增 ' . $failed . ' 条失败记录' : '' ), $created > 0 || 0 === $failed ? 'success' : 'error' );
		} elseif ( 'generate_forum_posts' === $action ) {
			$before  = self::activity_log_count( 'error' );
			$created = self::generate_forum_posts( $options );
			$failed  = self::activity_log_count( 'error' ) - $before;
			self::notice( sprintf( '已生成 %d 篇社区帖%s。', $created, $failed > 0 ? '，新增 ' . $failed . ' 条失败记录' : '' ), $created > 0 || 0 === $failed ? 'success' : 'error' );
		} elseif ( 'reschedule_cron' === $action ) {
			self::reschedule_event( $options );
			self::notice( '已重新安排定时任务。下次运行：' . self::next_run_text() );
		} elseif ( 'run_daily_now' === $action ) {
			if ( empty( $options['safety_confirmed'] ) ) {
				self::notice( '请先在“计划任务”页面勾选站点确认。', 'error' );
			} else {
				$before = self::activity_log_count( 'error' );
				$result = self::run_daily_once( $options );
				$failed = self::activity_log_count( 'error' ) - $before;
				self::notice( sprintf( '已执行一次每日任务：新增 %d 个用户、%d 条评论、%d 篇社区帖%s。', $result['users'], $result['comments'], $result['forum_posts'], $failed > 0 ? '，新增 ' . $failed . ' 条失败记录' : '' ) );
			}
		} elseif ( 'delete_comments' === $action ) {
			self::notice( sprintf( '已删除 %d 条插件评论。', self::delete_generated_comments() ) );
		} elseif ( 'delete_forum_posts' === $action ) {
			self::notice( sprintf( '已删除 %d 篇插件社区帖。', self::delete_generated_forum_posts() ) );
		} elseif ( 'delete_users' === $action ) {
			self::notice( sprintf( '已删除 %d 个插件用户。', self::delete_generated_users() ) );
		} elseif ( 'clear_logs' === $action ) {
			update_option( self::LOG_OPTION_KEY, array(), false );
			delete_transient( self::STATS_CACHE_KEY );
			self::notice( '运行日志已清空。' );
		} elseif ( 'check_update' === $action ) {
			$result = self::check_github_update();
			self::notice( $result['message'], empty( $result['ok'] ) ? 'error' : 'success' );
		} elseif ( 'manual_update' === $action ) {
			$result = self::manual_update();
			self::notice( $result['message'], empty( $result['ok'] ) ? 'error' : 'success' );
		}
	}

	public static function ajax_fetch_ai_models() {
		self::verify_ai_ajax_request();
		$options = self::ai_options_from_request();
		$result  = self::fetch_ai_models( $options );

		if ( empty( $result['ok'] ) ) {
			wp_send_json_error( array( 'message' => $result['message'] ?? '模型列表读取失败。' ) );
		}

		wp_send_json_success(
			array(
				'message'  => $result['message'],
				'models'   => $result['models'],
				'provider' => self::ai_provider_label( $options['ai_provider'] ),
			)
		);
	}

	public static function ajax_test_ai_connection() {
		self::verify_ai_ajax_request();
		$options = self::ai_options_from_request();
		$result  = self::test_ai_connection( $options );

		if ( empty( $result['ok'] ) ) {
			wp_send_json_error( array( 'message' => $result['message'] ?? '连接测试失败。' ) );
		}

		wp_send_json_success(
			array(
				'message'  => $result['message'],
				'provider' => $result['provider'],
				'model'    => $result['model'],
			)
		);
	}

	private static function verify_ai_ajax_request() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => '你没有权限执行此操作。' ), 403 );
		}

		if ( ! wp_doing_ajax() ) {
			wp_send_json_error( array( 'message' => '仅支持 AJAX 请求。' ), 400 );
		}

		check_ajax_referer( 'zibi_name_ai_tools', 'nonce' );
	}

	private static function ai_options_from_request() {
		$defaults = self::get_options();
		$provider = sanitize_key( self::posted_value( 'ai_provider', $defaults['ai_provider'] ) );
		$provider = in_array( $provider, array( 'compatible', 'gemini', 'openrouter', 'none' ), true ) ? $provider : $defaults['ai_provider'];

		return array(
			'ai_provider'        => $provider,
			'compatible_api_base' => esc_url_raw( self::posted_value( 'compatible_api_base', $defaults['compatible_api_base'] ) ),
			'compatible_api_key' => sanitize_text_field( self::posted_value( 'compatible_api_key', $defaults['compatible_api_key'] ) ),
			'compatible_model'   => sanitize_text_field( self::posted_value( 'compatible_model', $defaults['compatible_model'] ) ),
			'gemini_api_key'     => sanitize_text_field( self::posted_value( 'gemini_api_key', $defaults['gemini_api_key'] ) ),
			'openrouter_api_key' => sanitize_text_field( self::posted_value( 'openrouter_api_key', $defaults['openrouter_api_key'] ) ),
			'openrouter_model'   => sanitize_text_field( self::posted_value( 'openrouter_model', $defaults['openrouter_model'] ) ),
			'ai_model'           => sanitize_text_field( self::posted_value( 'ai_model', $defaults['ai_model'] ) ),
			'ai_timeout'         => min( 60, max( 3, absint( self::posted_value( 'ai_timeout', $defaults['ai_timeout'] ) ) ) ),
		);
	}

	private static function posted_value( $key, $fallback = '' ) {
		return isset( $_POST[ $key ] ) ? wp_unslash( $_POST[ $key ] ) : $fallback;
	}

	private static function notice( $message, $type = 'success' ) {
		add_settings_error( self::OPTION_KEY, 'zibi_name_notice', $message, $type );
	}

	private static function reschedule_event( array $options ) {
		self::clear_scheduled_event();
		wp_schedule_event( self::next_timestamp_for_time( $options['daily_run_time'] ?? '02:30' ), 'daily', self::CRON_HOOK );
	}

	private static function clear_scheduled_event() {
		while ( $timestamp = wp_next_scheduled( self::CRON_HOOK ) ) {
			wp_unschedule_event( $timestamp, self::CRON_HOOK );
		}
	}

	private static function next_timestamp_for_time( $time ) {
		$tz     = wp_timezone();
		$now    = new DateTimeImmutable( 'now', $tz );
		$target = DateTimeImmutable::createFromFormat( 'Y-m-d H:i', $now->format( 'Y-m-d' ) . ' ' . $time, $tz );
		if ( ! $target || $target <= $now ) {
			$target = $target ? $target->modify( '+1 day' ) : $now->modify( '+1 day' );
		}
		return $target->getTimestamp();
	}

	public static function run_daily_activity() {
		$options = self::get_options();
		if ( empty( $options['safety_confirmed'] ) || empty( $options['enabled'] ) ) {
			return;
		}
		self::run_daily_once( $options );
	}

	private static function run_daily_once( array $options ) {
		return array(
			'users'       => self::ensure_users( $options ),
			'comments'    => self::generate_comments( $options ),
			'forum_posts' => empty( $options['create_forum_posts'] ) ? 0 : self::generate_forum_posts( $options ),
		);
	}

	private static function ensure_users( array $options ) {
		$target  = absint( $options['user_pool_size'] );
		$needed  = $target - count( self::get_generated_users( $target ) );
		$created = 0;
		$skipped = 0;
		$errors  = 0;
		$nicknames = self::nickname_candidates( $options, $needed );
		for ( $i = 0; $i < $needed; $i++ ) {
			$suffix = strtolower( wp_generate_password( 8, false, false ) );
			$login  = sanitize_user( $options['user_login_prefix'] . $suffix, true );
			$email  = $login . '@example.invalid';
			if ( username_exists( $login ) || email_exists( $email ) ) {
				$skipped++;
				if ( $skipped <= 5 ) {
					self::add_activity_log( 'user', 'warning', '用户创建跳过：登录名或邮箱已存在。', array( 'login' => $login, 'email' => $email ) );
				}
				continue;
			}
			$nickname = ! empty( $nicknames ) ? array_shift( $nicknames ) : self::random_nickname();
			$user_id  = wp_insert_user(
				array(
					'user_login'   => $login,
					'user_pass'    => wp_generate_password( 24, true, true ),
					'user_email'   => $email,
					'display_name' => $nickname,
					'nickname'     => $nickname,
					'description'  => self::random_signature(),
					'role'         => 'subscriber',
				)
			);
			if ( is_wp_error( $user_id ) ) {
				$errors++;
				self::add_activity_log( 'user', 'error', '用户创建失败：' . $user_id->get_error_message(), array( 'login' => $login, 'email' => $email ) );
				continue;
			}
			update_user_meta( $user_id, self::USER_META_KEY, 1 );
			update_user_meta( $user_id, 'custom_avatar', self::random_avatar_url( $user_id ) );
			update_user_meta( $user_id, 'gender', self::random_item( array( '保密', '男', '女' ) ) );
			$created++;
			delete_transient( self::STATS_CACHE_KEY );
		}
		if ( $needed > 0 && $created < $needed ) {
			self::add_activity_log(
				'user',
				'warning',
				'用户池未完全补齐，请查看前面的用户创建记录。',
				array(
					'target'  => $target,
					'needed'  => $needed,
					'created' => $created,
					'skipped' => $skipped,
					'errors'  => $errors,
				)
			);
		}
		return $created;
	}

	private static function generate_comments( array $options ) {
		self::ensure_users( $options );
		if ( absint( $options['daily_comments'] ) <= 0 ) {
			self::add_activity_log( 'comment', 'warning', '评论未生成：每日评论数量设置为 0。' );
			return 0;
		}
		$targets = self::candidate_comment_targets( $options );
		if ( empty( $targets ) ) {
			self::add_activity_log(
				'comment',
				'error',
				'没有找到可评论的内容。请确认目标内容已发布、评论已开启，并且抽取范围足够大。',
				array(
					'post_pool_size'      => absint( $options['post_pool_size'] ),
					'target_post'         => empty( $options['target_post'] ) ? '关闭' : '开启',
					'target_forum_post'   => empty( $options['target_forum_post'] ) ? '关闭' : '开启',
				)
			);
			return 0;
		}
		$created = 0;
		for ( $i = 0; $i < absint( $options['daily_comments'] ); $i++ ) {
			$post = $targets[ array_rand( $targets ) ];
			$user = self::random_generated_user();
			if ( ! $user ) {
				self::add_activity_log( 'comment', 'error', '没有可用的用户池账号，无法发布评论。', array( 'target_post_id' => $post->ID ) );
				break;
			}
			$content_meta = array();
			$content = trim( wp_strip_all_tags( self::comment_text( $post, $options, $content_meta ) ) );
			if ( '' === $content ) {
				self::add_activity_log(
					'comment',
					'error',
					'评论内容为空，AI 和备用模板都没有返回可发布内容。',
					array(
						'target_post_id' => $post->ID,
						'title'          => get_the_title( $post ),
						'provider'       => $content_meta['provider'] ?? '',
						'model'          => $content_meta['model'] ?? '',
						'ai_error'       => $content_meta['error'] ?? '',
					)
				);
				continue;
			}
			$comment_id = wp_insert_comment(
				array(
					'comment_post_ID'      => $post->ID,
					'user_id'              => $user->ID,
					'comment_author'       => $user->display_name,
					'comment_author_email' => $user->user_email,
					'comment_content'      => $content,
					'comment_type'         => 'comment',
					'comment_approved'     => 'approve' === $options['comment_status'] ? 1 : 0,
					'comment_date'         => current_time( 'mysql' ),
					'comment_date_gmt'     => current_time( 'mysql', true ),
				)
			);
			if ( is_wp_error( $comment_id ) ) {
				self::add_activity_log(
					'comment',
					'error',
					'评论发布失败：' . $comment_id->get_error_message(),
					array(
						'target_post_id' => $post->ID,
						'user_id'        => $user->ID,
						'provider'       => $content_meta['provider'] ?? '',
						'model'          => $content_meta['model'] ?? '',
					)
				);
			} elseif ( $comment_id ) {
				add_comment_meta( $comment_id, self::COMMENT_META_KEY, 1, true );
				add_comment_meta( $comment_id, self::DATE_META_KEY, current_time( 'Y-m-d' ), true );
				self::store_comment_ai_meta( $comment_id, $content_meta );
				self::log_comment_creation( $comment_id, $post->ID, $user->ID, $content_meta );
				$created++;
			} else {
				self::add_activity_log(
					'comment',
					'error',
					'评论发布失败：WordPress 未返回评论 ID。',
					array(
						'target_post_id' => $post->ID,
						'user_id'        => $user->ID,
						'provider'       => $content_meta['provider'] ?? '',
						'model'          => $content_meta['model'] ?? '',
					)
				);
			}
		}
		return $created;
	}

	private static function generate_forum_posts( array $options ) {
		if ( absint( $options['daily_forum_posts'] ) <= 0 ) {
			self::add_activity_log( 'forum', 'warning', '社区帖未生成：每日社区帖数量设置为 0。' );
			return 0;
		}
		if ( ! post_type_exists( 'forum_post' ) ) {
			self::add_activity_log( 'forum', 'error', '无法发布社区帖：当前站点未注册 forum_post 内容类型。' );
			return 0;
		}
		self::ensure_users( $options );
		$created = 0;
		for ( $i = 0; $i < absint( $options['daily_forum_posts'] ); $i++ ) {
			$user = self::random_generated_user();
			if ( ! $user ) {
				self::add_activity_log( 'forum', 'error', '没有可用的用户池账号，无法发布社区帖。' );
				break;
			}
			$title        = self::random_line( $options['fallback_forum_titles'] );
			$content_meta = array();
			$content      = self::forum_content( $title, $options, $content_meta );
			if ( '' === trim( $title ) ) {
				self::add_activity_log( 'forum', 'error', '社区帖标题为空，请补充备用社区帖标题。', array( 'user_id' => $user->ID ) );
				continue;
			}
			if ( '' === trim( wp_strip_all_tags( $content ) ) ) {
				self::add_activity_log(
					'forum',
					'error',
					'社区帖正文为空，AI 和备用模板都没有返回可发布内容。',
					array(
						'title'    => $title,
						'user_id'  => $user->ID,
						'provider' => $content_meta['provider'] ?? '',
						'model'    => $content_meta['model'] ?? '',
						'ai_error' => $content_meta['error'] ?? '',
					)
				);
				continue;
			}
			$plate   = self::random_plate_id();
			$topic   = self::random_term_id( 'forum_topic' );
			$tags    = self::random_term_ids( 'forum_tag', 2 );
			$post_id = wp_insert_post(
				array(
					'post_type'      => 'forum_post',
					'post_title'     => $title,
					'post_content'   => $content,
					'post_status'    => 'publish',
					'post_author'    => $user->ID,
					'comment_status' => 'open',
					'meta_input'     => array( 'plate_id' => $plate, self::POST_META_KEY => 1, self::DATE_META_KEY => current_time( 'Y-m-d' ) ),
				),
				true
			);
			if ( is_wp_error( $post_id ) ) {
				self::add_activity_log(
					'forum',
					'error',
					'社区帖发布失败：' . $post_id->get_error_message(),
					array(
						'title'    => $title,
						'user_id'  => $user->ID,
						'plate_id' => $plate,
						'provider' => $content_meta['provider'] ?? '',
						'model'    => $content_meta['model'] ?? '',
					)
				);
				continue;
			}
			self::store_post_ai_meta( $post_id, $content_meta );
			self::log_forum_post_creation( $post_id, $user->ID, $content_meta );
			if ( $plate ) {
				update_post_meta( $post_id, 'plate_id', $plate );
			}
			if ( $topic ) {
				$term_result = wp_set_post_terms( $post_id, array( $topic ), 'forum_topic' );
				if ( is_wp_error( $term_result ) ) {
					self::add_activity_log( 'forum', 'warning', '社区帖已发布，但话题绑定失败：' . $term_result->get_error_message(), array( 'post_id' => $post_id, 'topic_id' => $topic ) );
				}
			}
			if ( ! empty( $tags ) ) {
				$tag_result = wp_set_post_terms( $post_id, $tags, 'forum_tag' );
				if ( is_wp_error( $tag_result ) ) {
					self::add_activity_log( 'forum', 'warning', '社区帖已发布，但标签绑定失败：' . $tag_result->get_error_message(), array( 'post_id' => $post_id ) );
				}
			}
			$created++;
		}
		return $created;
	}

	private static function candidate_comment_targets( array $options ) {
		$types = array();
		if ( ! empty( $options['target_post'] ) ) {
			$types[] = 'post';
		}
		if ( ! empty( $options['target_forum_post'] ) && post_type_exists( 'forum_post' ) ) {
			$types[] = 'forum_post';
		}
		return empty( $types ) ? array() : get_posts( array( 'post_type' => $types, 'post_status' => 'publish', 'posts_per_page' => absint( $options['post_pool_size'] ), 'orderby' => 'date', 'order' => 'DESC', 'comment_status' => 'open' ) );
	}

	private static function comment_text( WP_Post $post, array $options, &$meta = null ) {
		$meta = array(
			'source'   => 'template',
			'provider' => '',
			'model'    => '',
			'error'    => '',
		);

		if ( ! empty( $options['use_ai'] ) ) {
			$ai_meta = array();
			$ai = self::request_ai_text( get_the_title( $post ), has_excerpt( $post ) ? get_the_excerpt( $post ) : wp_trim_words( wp_strip_all_tags( $post->post_content ), 120 ), $options, 'comment', $ai_meta );
			if ( $ai ) {
				$meta = $ai_meta;
				$meta['source'] = 'ai';
				return $ai;
			}
			if ( ! empty( $ai_meta['error'] ) ) {
				$meta = $ai_meta;
				$meta['source'] = 'template';
			}
		}
		$style = 'mixed' === $options['comment_style'] ? self::random_item( array( 'question', 'agree', 'supplement', 'discussion' ) ) : $options['comment_style'];
		$map   = self::comment_templates();
		return sprintf( self::random_item( $map[ $style ] ?? $map['discussion'] ), get_the_title( $post ) );
	}

	private static function forum_content( $title, array $options, &$meta = null ) {
		$meta = array(
			'source'   => 'template',
			'provider' => '',
			'model'    => '',
			'error'    => '',
		);

		if ( ! empty( $options['use_ai'] ) ) {
			$ai_meta = array();
			$ai = self::request_ai_text( $title, '', $options, 'forum', $ai_meta );
			if ( $ai ) {
				$meta = $ai_meta;
				$meta['source'] = 'ai';
				return $ai;
			}
			if ( ! empty( $ai_meta['error'] ) ) {
				$meta = $ai_meta;
				$meta['source'] = 'template';
			}
		}
		return self::random_line( $options['fallback_forum_contents'] );
	}

	private static function request_ai_text( $title, $excerpt, array $options, $mode, &$meta = null ) {
		$meta = array(
			'source'   => 'ai',
			'provider' => self::ai_provider_label( $options['ai_provider'] ?? 'none' ),
			'model'    => self::current_ai_model( $options ),
			'error'    => '',
		);

		if ( empty( $options['use_ai'] ) || empty( $options['ai_provider'] ) || 'none' === $options['ai_provider'] ) {
			$meta['error'] = 'AI 未启用或未选择服务。';
			return '';
		}

		$prompt = ( 'comment' === $mode ? $options['ai_prompt'] : '请为当前 WP 系统社区写一段自然的中文帖子正文，只返回正文。' ) . "\n\n标题：" . $title . "\n摘要：" . $excerpt;

		if ( 'compatible' === $options['ai_provider'] ) {
			return self::request_compatible_text( $prompt, $options, $mode, $meta );
		}

		if ( 'gemini' === $options['ai_provider'] ) {
			return self::request_gemini_text( $prompt, $options, $meta );
		}

		if ( 'openrouter' === $options['ai_provider'] ) {
			return self::request_openrouter_text( $prompt, $options, $mode, $meta );
		}

		$meta['error'] = '不支持的 AI 服务。';
		return '';
	}

	private static function fetch_ai_models( array $options ) {
		if ( 'compatible' === $options['ai_provider'] ) {
			return self::fetch_openai_compatible_models( $options['compatible_api_base'], $options['compatible_api_key'], 'OpenAI 兼容接口' );
		}

		if ( 'openrouter' === $options['ai_provider'] ) {
			$headers = array( 'Accept' => 'application/json' );
			if ( ! empty( $options['openrouter_api_key'] ) ) {
				$headers['Authorization'] = 'Bearer ' . $options['openrouter_api_key'];
			}
			return self::request_model_list( 'https://openrouter.ai/api/v1/models', $headers, 'OpenRouter' );
		}

		if ( 'gemini' === $options['ai_provider'] ) {
			if ( empty( $options['gemini_api_key'] ) ) {
				return array( 'ok' => false, 'message' => '请先填写 Gemini API Key。' );
			}
			return self::request_model_list( 'https://generativelanguage.googleapis.com/v1beta/models?key=' . rawurlencode( $options['gemini_api_key'] ), array( 'Accept' => 'application/json' ), 'Google Gemini' );
		}

		return array( 'ok' => false, 'message' => '请先选择可用的 AI 服务。' );
	}

	private static function fetch_openai_compatible_models( $base_url, $api_key, $provider_label ) {
		if ( empty( $base_url ) ) {
			return array( 'ok' => false, 'message' => '请先填写兼容接口地址。' );
		}
		if ( empty( $api_key ) ) {
			return array( 'ok' => false, 'message' => '请先填写兼容接口 Key。' );
		}

		$url = self::openai_compatible_models_url( $base_url );
		if ( ! $url ) {
			return array( 'ok' => false, 'message' => '兼容接口地址格式不正确，请填写 http 或 https 开头的地址。' );
		}

		return self::request_model_list(
			$url,
			array(
				'Accept'        => 'application/json',
				'Authorization' => 'Bearer ' . $api_key,
			),
			$provider_label
		);
	}

	private static function request_model_list( $url, array $headers, $provider_label ) {
		$response = wp_remote_get(
			$url,
			array(
				'timeout' => 20,
				'headers' => $headers,
			)
		);

		if ( is_wp_error( $response ) ) {
			return array( 'ok' => false, 'message' => '模型列表读取失败：' . $response->get_error_message() );
		}

		$code = wp_remote_retrieve_response_code( $response );
		if ( $code < 200 || $code >= 300 ) {
			return array( 'ok' => false, 'message' => '模型列表读取失败：' . self::remote_response_error_message( $response ) );
		}

		$body = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( ! is_array( $body ) ) {
			return array( 'ok' => false, 'message' => '模型列表读取失败：接口没有返回有效 JSON。' );
		}

		$models = self::extract_model_ids( $body, $provider_label );
		if ( empty( $models ) ) {
			return array( 'ok' => false, 'message' => '模型列表为空，请确认当前 Key 有模型读取权限。' );
		}

		return array(
			'ok'      => true,
			'message' => sprintf( '%s 已读取到 %d 个模型，点击模型名可填入当前模型框。', $provider_label, count( $models ) ),
			'models'  => array_slice( $models, 0, 300 ),
		);
	}

	private static function extract_model_ids( array $body, $provider_label = '' ) {
		$sources = array();
		if ( isset( $body['data'] ) && is_array( $body['data'] ) ) {
			$sources[] = $body['data'];
		}
		if ( isset( $body['models'] ) && is_array( $body['models'] ) ) {
			$sources[] = $body['models'];
		}
		if ( empty( $sources ) && self::is_list_array( $body ) ) {
			$sources[] = $body;
		}

		$models = array();
		foreach ( $sources as $items ) {
			foreach ( $items as $item ) {
				$id = '';
				if ( is_string( $item ) ) {
					$id = $item;
				} elseif ( is_array( $item ) ) {
					if ( false !== stripos( $provider_label, 'Gemini' ) && ! empty( $item['supportedGenerationMethods'] ) && is_array( $item['supportedGenerationMethods'] ) && ! in_array( 'generateContent', $item['supportedGenerationMethods'], true ) ) {
						continue;
					}
					$id = $item['id'] ?? ( $item['name'] ?? '' );
				}

				$id = preg_replace( '/^models\//', '', trim( (string) $id ) );
				if ( '' !== $id && strlen( $id ) <= 180 ) {
					$models[] = $id;
				}
			}
		}

		$models = array_values( array_unique( $models ) );
		natcasesort( $models );
		return array_values( $models );
	}

	private static function is_list_array( array $array ) {
		if ( empty( $array ) ) {
			return true;
		}

		return array_keys( $array ) === range( 0, count( $array ) - 1 );
	}

	private static function test_ai_connection( array $options ) {
		$meta = array();
		$text = '';

		if ( 'compatible' === $options['ai_provider'] ) {
			$text = self::request_compatible_text( '请只回复 OK。', $options, 'test', $meta );
		} elseif ( 'openrouter' === $options['ai_provider'] ) {
			$text = self::request_openrouter_text( '请只回复 OK。', $options, 'test', $meta );
		} elseif ( 'gemini' === $options['ai_provider'] ) {
			$text = self::request_gemini_text( '请只回复 OK。', $options, $meta );
		} else {
			return array( 'ok' => false, 'message' => '请先选择可用的 AI 服务。' );
		}

		if ( '' === trim( (string) $text ) ) {
			return array(
				'ok'      => false,
				'message' => '连接测试失败：' . ( $meta['error'] ?? '接口没有返回内容。' ),
			);
		}

		return array(
			'ok'       => true,
			'message'  => sprintf( '连接成功，当前模型：%s。接口返回：%s', $meta['model'] ?? self::current_ai_model( $options ), wp_html_excerpt( $text, 80, '...' ) ),
			'provider' => $meta['provider'] ?? self::ai_provider_label( $options['ai_provider'] ),
			'model'    => $meta['model'] ?? self::current_ai_model( $options ),
		);
	}

	private static function ai_provider_label( $provider ) {
		$labels = array(
			'compatible' => 'OpenAI 兼容接口（New API / sub2api / One API）',
			'openrouter' => 'OpenRouter',
			'gemini'     => 'Google Gemini',
			'none'       => '不使用 AI',
		);

		return $labels[ sanitize_key( $provider ) ] ?? 'AI';
	}

	private static function current_ai_model( array $options ) {
		if ( 'compatible' === ( $options['ai_provider'] ?? '' ) ) {
			return $options['compatible_model'] ?? '';
		}
		if ( 'openrouter' === ( $options['ai_provider'] ?? '' ) ) {
			return $options['openrouter_model'] ?? '';
		}
		if ( 'gemini' === ( $options['ai_provider'] ?? '' ) ) {
			return $options['ai_model'] ?? '';
		}

		return '';
	}

	private static function request_gemini_text( $prompt, array $options, &$meta = null ) {
		$model = ! empty( $options['ai_model'] ) ? $options['ai_model'] : self::defaults()['ai_model'];
		$model = preg_replace( '/^models\//', '', $model );
		$meta  = array(
			'source'   => 'ai',
			'provider' => self::ai_provider_label( 'gemini' ),
			'model'    => $model,
			'error'    => '',
		);

		if ( empty( $options['gemini_api_key'] ) ) {
			$meta['error'] = '缺少 Gemini API Key。';
			return '';
		}

		$url    = 'https://generativelanguage.googleapis.com/v1beta/models/' . rawurlencode( $model ) . ':generateContent?key=' . rawurlencode( $options['gemini_api_key'] );
		$response = wp_remote_post(
			$url,
			array(
				'timeout' => absint( $options['ai_timeout'] ),
				'headers' => array( 'Content-Type' => 'application/json' ),
				'body'    => wp_json_encode( array( 'contents' => array( array( 'parts' => array( array( 'text' => $prompt ) ) ) ) ) ),
			)
		);
		if ( is_wp_error( $response ) ) {
			$meta['error'] = $response->get_error_message();
			return '';
		}

		$code = wp_remote_retrieve_response_code( $response );
		if ( $code < 200 || $code >= 300 ) {
			$meta['error'] = self::remote_response_error_message( $response );
			return '';
		}

		$body = json_decode( wp_remote_retrieve_body( $response ), true );
		$text = '';
		if ( ! empty( $body['candidates'][0]['content']['parts'] ) && is_array( $body['candidates'][0]['content']['parts'] ) ) {
			foreach ( $body['candidates'][0]['content']['parts'] as $part ) {
				$text .= $part['text'] ?? '';
			}
		}

		$text = self::clean_ai_text( $text );
		if ( '' === $text ) {
			$meta['error'] = '接口返回为空。';
		}

		return $text;
	}

	private static function request_compatible_text( $prompt, array $options, $mode, &$meta = null ) {
		$meta = array(
			'source'   => 'ai',
			'provider' => self::ai_provider_label( 'compatible' ),
			'model'    => $options['compatible_model'] ?? '',
			'error'    => '',
		);

		if ( empty( $options['compatible_api_base'] ) || empty( $options['compatible_api_key'] ) || empty( $options['compatible_model'] ) ) {
			$meta['error'] = '请填写兼容接口地址、Key 和模型名。';
			return '';
		}

		$url = self::openai_compatible_chat_url( $options['compatible_api_base'] );
		if ( ! $url ) {
			$meta['error'] = '兼容接口地址格式不正确。';
			return '';
		}

		return self::request_openai_compatible_text( $url, $options['compatible_api_key'], $options['compatible_model'], $prompt, $options, $mode, $meta, self::ai_provider_label( 'compatible' ) );
	}

	private static function request_openrouter_text( $prompt, array $options, $mode, &$meta = null ) {
		$model = ! empty( $options['openrouter_model'] ) ? $options['openrouter_model'] : self::defaults()['openrouter_model'];
		$meta  = array(
			'source'   => 'ai',
			'provider' => self::ai_provider_label( 'openrouter' ),
			'model'    => $model,
			'error'    => '',
		);

		if ( empty( $options['openrouter_api_key'] ) ) {
			$meta['error'] = '缺少 OpenRouter API Key。';
			return '';
		}

		return self::request_openai_compatible_text( 'https://openrouter.ai/api/v1/chat/completions', $options['openrouter_api_key'], $model, $prompt, $options, $mode, $meta, self::ai_provider_label( 'openrouter' ) );
	}

	private static function request_openai_compatible_text( $url, $api_key, $model, $prompt, array $options, $mode, &$meta = null, $provider_label = 'OpenAI 兼容接口' ) {
		$meta = array(
			'source'   => 'ai',
			'provider' => $provider_label,
			'model'    => $model,
			'error'    => '',
		);

		$response = wp_remote_post(
			$url,
			array(
				'timeout' => absint( $options['ai_timeout'] ),
				'headers' => array(
					'Authorization' => 'Bearer ' . $api_key,
					'Content-Type'  => 'application/json',
					'HTTP-Referer'  => home_url( '/' ),
					'X-Title'       => wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES ),
				),
				'body'    => wp_json_encode(
					array(
						'model'       => $model,
						'messages'    => array(
							array(
								'role'    => 'system',
								'content' => '你只输出自然中文正文，不要解释。',
							),
							array(
								'role'    => 'user',
								'content' => $prompt,
							),
						),
						'temperature' => 0.8,
						'max_tokens'  => 'test' === $mode ? 32 : ( 'nickname' === $mode ? 420 : ( 'comment' === $mode ? 160 : 280 ) ),
					)
				),
			)
		);
		if ( is_wp_error( $response ) ) {
			$meta['error'] = $response->get_error_message();
			return '';
		}

		$code = wp_remote_retrieve_response_code( $response );
		if ( $code < 200 || $code >= 300 ) {
			$meta['error'] = self::remote_response_error_message( $response );
			return '';
		}

		$body = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( ! is_array( $body ) ) {
			$meta['error'] = '接口没有返回有效 JSON。';
			return '';
		}

		$text = self::clean_ai_text( $body['choices'][0]['message']['content'] ?? '' );
		if ( '' === $text ) {
			$meta['error'] = '接口返回为空。';
		}

		return $text;
	}

	private static function openai_compatible_chat_url( $base_url ) {
		$base_url = trim( (string) $base_url );
		if ( '' === $base_url || ! preg_match( '#^https?://#i', $base_url ) ) {
			return '';
		}

		$base_url = rtrim( $base_url, '/' );
		if ( preg_match( '#/chat/completions$#i', $base_url ) ) {
			return $base_url;
		}
		if ( preg_match( '#/v1$#i', $base_url ) ) {
			return $base_url . '/chat/completions';
		}

		return $base_url . '/v1/chat/completions';
	}

	private static function openai_compatible_models_url( $base_url ) {
		$base_url = trim( (string) $base_url );
		if ( '' === $base_url || ! preg_match( '#^https?://#i', $base_url ) ) {
			return '';
		}

		$base_url = rtrim( $base_url, '/' );
		if ( preg_match( '#/models$#i', $base_url ) ) {
			return $base_url;
		}
		if ( preg_match( '#/chat/completions$#i', $base_url ) ) {
			return preg_replace( '#/chat/completions$#i', '/models', $base_url );
		}
		if ( preg_match( '#/v1$#i', $base_url ) ) {
			return $base_url . '/models';
		}

		return $base_url . '/v1/models';
	}

	private static function remote_response_error_message( $response ) {
		$code = wp_remote_retrieve_response_code( $response );
		$body = wp_remote_retrieve_body( $response );
		$json = json_decode( $body, true );
		$message = '';

		if ( is_array( $json ) ) {
			if ( ! empty( $json['error']['message'] ) ) {
				$message = $json['error']['message'];
			} elseif ( ! empty( $json['message'] ) ) {
				$message = $json['message'];
			} elseif ( ! empty( $json['error'] ) && is_string( $json['error'] ) ) {
				$message = $json['error'];
			}
		}

		if ( '' === $message && '' !== trim( (string) $body ) ) {
			$message = wp_html_excerpt( wp_strip_all_tags( (string) $body ), 160, '...' );
		}

		return 'HTTP ' . absint( $code ) . ( $message ? '：' . $message : '' );
	}

	private static function clean_ai_text( $text ) {
		$text = trim( wp_strip_all_tags( (string) $text ) );
		$text = preg_replace( '/^["“”]+|["“”]+$/u', '', $text );
		return trim( $text );
	}

	private static function check_github_update() {
		$response = wp_remote_get(
			'https://api.github.com/repos/' . self::UPDATE_REPOSITORY . '/releases/latest',
			array(
				'timeout' => 20,
				'headers' => array(
					'Accept'     => 'application/vnd.github+json',
					'User-Agent' => self::PLUGIN_SLUG . '/' . ZIBI_NAME_VERSION,
				),
			)
		);

		if ( is_wp_error( $response ) ) {
			return array( 'ok' => false, 'message' => '检查失败：' . $response->get_error_message() );
		}

		$code = wp_remote_retrieve_response_code( $response );
		$body = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( $code < 200 || $code >= 300 ) {
			$message = is_array( $body ) && ! empty( $body['message'] ) ? $body['message'] : 'HTTP ' . absint( $code );
			return array( 'ok' => false, 'message' => 'GitHub Release 读取失败：' . $message );
		}

		if ( empty( $body['tag_name'] ) ) {
			return array( 'ok' => false, 'message' => '未读取到最新 Release，请确认仓库已经发布 Release。' );
		}

		$download = '';
		if ( ! empty( $body['assets'] ) && is_array( $body['assets'] ) ) {
			foreach ( $body['assets'] as $asset ) {
				if ( ! empty( $asset['name'] ) && self::UPDATE_ASSET_NAME === $asset['name'] && ! empty( $asset['browser_download_url'] ) ) {
					$download = $asset['browser_download_url'];
					break;
				}
			}
		}

		if ( ! $download ) {
			return array( 'ok' => false, 'message' => '最新 Release 必须上传附件 ' . self::UPDATE_ASSET_NAME . '，否则无法执行插件更新。' );
		}

		$tag     = sanitize_text_field( $body['tag_name'] );
		$version = preg_replace( '/^v/i', '', $tag );
		set_transient(
			self::UPDATE_CACHE_KEY,
			array(
				'version'      => $version,
				'tag_name'     => $tag,
				'html_url'     => ! empty( $body['html_url'] ) ? esc_url_raw( $body['html_url'] ) : self::UPDATE_REPOSITORY_URL,
				'download_url' => esc_url_raw( $download ),
				'asset_name'   => self::UPDATE_ASSET_NAME,
				'published_at' => ! empty( $body['published_at'] ) ? sanitize_text_field( $body['published_at'] ) : '',
				'body'         => ! empty( $body['body'] ) ? wp_kses_post( $body['body'] ) : '',
			),
			HOUR_IN_SECONDS
		);

		return array( 'ok' => true, 'message' => '已检查 GitHub 最新版本：' . $tag . '（' . $version . '）' );
	}

	public static function inject_plugin_update( $transient ) {
		if ( empty( $transient ) || ! is_object( $transient ) ) {
			return $transient;
		}

		$cache = get_transient( self::UPDATE_CACHE_KEY );

		if ( ! is_array( $cache ) ) {
			$result = self::check_github_update();
			$cache  = ! empty( $result['ok'] ) ? get_transient( self::UPDATE_CACHE_KEY ) : array();
		}

		if ( empty( $cache['version'] ) || empty( $cache['download_url'] ) || version_compare( $cache['version'], ZIBI_NAME_VERSION, '<=' ) ) {
			return $transient;
		}

		$plugin = plugin_basename( ZIBI_NAME_FILE );
		$transient->response[ $plugin ] = (object) array(
			'id'          => $plugin,
			'slug'        => self::PLUGIN_SLUG,
			'plugin'      => $plugin,
			'new_version' => $cache['version'],
			'url'         => $cache['html_url'] ?? '',
			'package'     => $cache['download_url'],
		);

		return $transient;
	}

	public static function plugin_info( $result, $action, $args ) {
		if ( 'plugin_information' !== $action || empty( $args->slug ) || self::PLUGIN_SLUG !== $args->slug ) {
			return $result;
		}

		$cache = get_transient( self::UPDATE_CACHE_KEY );
		return (object) array(
			'name'          => 'CamFlow',
			'slug'          => self::PLUGIN_SLUG,
			'version'       => is_array( $cache ) && ! empty( $cache['version'] ) ? $cache['version'] : ZIBI_NAME_VERSION,
			'author'        => 'CAMWT',
			'homepage'      => self::OFFICIAL_SITE_URL,
			'sections'      => array(
				'description' => '面向 WP 系统的内容互动自动化工具，支持用户池、评论、社区帖、AI 接入和运行日志。',
				'changelog'   => is_array( $cache ) && ! empty( $cache['body'] ) ? wp_kses_post( $cache['body'] ) : '暂无更新说明。',
			),
			'download_link' => is_array( $cache ) && ! empty( $cache['download_url'] ) ? $cache['download_url'] : '',
		);
	}

	public static function normalize_update_source( $source, $remote_source, $upgrader, $hook_extra ) {
		if ( is_wp_error( $source ) || empty( $hook_extra['plugin'] ) || plugin_basename( ZIBI_NAME_FILE ) !== $hook_extra['plugin'] ) {
			return $source;
		}

		$source = trailingslashit( $source );
		if ( self::PLUGIN_DIRNAME === basename( untrailingslashit( $source ) ) ) {
			return $source;
		}

		global $wp_filesystem;
		if ( empty( $wp_filesystem ) ) {
			return $source;
		}

		$plugin_file = trailingslashit( $source ) . self::PLUGIN_FILENAME;
		if ( ! $wp_filesystem->exists( $plugin_file ) ) {
			return $source;
		}

		$target = trailingslashit( dirname( untrailingslashit( $source ) ) ) . self::PLUGIN_DIRNAME . '/';
		if ( $wp_filesystem->exists( $target ) ) {
			$wp_filesystem->delete( $target, true );
		}

		if ( ! $wp_filesystem->move( $source, $target ) ) {
			return new WP_Error( 'zibi_name_update_source_failed', '无法整理 GitHub 更新包目录，请确认服务器文件权限。' );
		}

		return $target;
	}

	private static function manual_update() {
		if ( ! current_user_can( 'update_plugins' ) ) {
			return array( 'ok' => false, 'message' => '当前用户没有更新插件权限。' );
		}

		$cache    = get_transient( self::UPDATE_CACHE_KEY );
		$package  = is_array( $cache ) && ! empty( $cache['download_url'] ) ? $cache['download_url'] : '';
		$version  = is_array( $cache ) && ! empty( $cache['version'] ) ? $cache['version'] : ZIBI_NAME_VERSION;
		$html_url = is_array( $cache ) && ! empty( $cache['html_url'] ) ? $cache['html_url'] : self::UPDATE_REPOSITORY_URL;

		if ( ! $package ) {
			$result = self::check_github_update();
			if ( empty( $result['ok'] ) ) {
				return $result;
			}
			$cache    = get_transient( self::UPDATE_CACHE_KEY );
			$package  = is_array( $cache ) && ! empty( $cache['download_url'] ) ? $cache['download_url'] : '';
			$version  = is_array( $cache ) && ! empty( $cache['version'] ) ? $cache['version'] : $version;
			$html_url = is_array( $cache ) && ! empty( $cache['html_url'] ) ? $cache['html_url'] : $html_url;
		}

		if ( version_compare( $version, ZIBI_NAME_VERSION, '<=' ) ) {
			return array( 'ok' => true, 'message' => '已是最新版本，无需更新。' );
		}

		if ( ! $package ) {
			return array( 'ok' => false, 'message' => '没有可用的更新包地址。' );
		}

		$plugin  = plugin_basename( ZIBI_NAME_FILE );
		$current = get_site_transient( 'update_plugins' );
		if ( ! is_object( $current ) ) {
			$current = new stdClass();
		}
		if ( empty( $current->response ) || ! is_array( $current->response ) ) {
			$current->response = array();
		}

		$current->response[ $plugin ] = (object) array(
			'id'          => $plugin,
			'slug'        => self::PLUGIN_SLUG,
			'plugin'      => $plugin,
			'new_version' => $version,
			'url'         => $html_url,
			'package'     => $package,
		);
		set_site_transient( 'update_plugins', $current );

		require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
		require_once ABSPATH . 'wp-admin/includes/class-plugin-upgrader.php';
		require_once ABSPATH . 'wp-admin/includes/file.php';

		$skin     = new Automatic_Upgrader_Skin();
		$upgrader = new Plugin_Upgrader( $skin );
		$result   = $upgrader->upgrade( $plugin );

		if ( is_wp_error( $result ) ) {
			return array( 'ok' => false, 'message' => '更新失败：' . $result->get_error_message() );
		}
		if ( false === $result ) {
			return array( 'ok' => false, 'message' => '更新未执行，请确认更新包是完整插件 zip，且服务器有文件写入权限。' );
		}

		return array( 'ok' => true, 'message' => '更新流程已执行，请刷新插件页面确认版本。' );
	}

	private static function get_generated_users( $limit = 3000 ) {
		$limit = absint( $limit );
		$limit = $limit > 0 ? $limit : self::MAX_USER_POOL_SIZE;
		return get_users( array( 'number' => $limit, 'fields' => 'all', 'meta_key' => self::USER_META_KEY, 'meta_value' => 1, 'orderby' => 'ID', 'order' => 'DESC' ) );
	}

	private static function get_generated_comments( $limit = 200 ) {
		$limit = absint( $limit );
		$limit = $limit > 0 ? $limit : self::DEFAULT_USER_DISPLAY_LIMIT;
		return get_comments( array( 'status' => 'all', 'number' => $limit, 'meta_key' => self::COMMENT_META_KEY, 'orderby' => 'comment_ID', 'order' => 'DESC' ) );
	}

	private static function get_generated_forum_posts( $limit = 200 ) {
		$limit = absint( $limit );
		$limit = $limit > 0 ? $limit : self::DEFAULT_USER_DISPLAY_LIMIT;
		return get_posts( array( 'post_type' => 'forum_post', 'post_status' => 'any', 'posts_per_page' => $limit, 'meta_key' => self::POST_META_KEY, 'meta_value' => 1, 'orderby' => 'ID', 'order' => 'DESC' ) );
	}

	private static function random_generated_user() {
		$users = self::get_generated_users( self::MAX_USER_POOL_SIZE );
		return empty( $users ) ? null : $users[ array_rand( $users ) ];
	}

	private static function nickname_candidates( array $options, $count ) {
		$count = absint( $count );
		if ( $count <= 0 ) {
			return array();
		}

		$names = self::ai_nickname_candidates( $options, $count );
		$names = array_values( array_unique( array_filter( $names ) ) );
		while ( count( $names ) < $count ) {
			$name = self::random_nickname();
			if ( ! in_array( $name, $names, true ) ) {
				$names[] = $name;
			}
		}

		return array_slice( $names, 0, $count );
	}

	private static function ai_nickname_candidates( array $options, $count ) {
		if ( empty( $options['use_ai'] ) || empty( $options['ai_provider'] ) || 'none' === $options['ai_provider'] ) {
			return array();
		}

		$count   = min( 200, max( 1, absint( $count ) ) );
		$names   = array();
		$batches = min( 4, (int) ceil( $count / 50 ) );
		for ( $i = 0; $i < $batches && count( $names ) < $count; $i++ ) {
			$batch_count = min( 50, $count - count( $names ) );
			$prompt      = sprintf( '请生成 %d 个适合中文 WP 社区的自然昵称，每行一个。要求抽象、轻量、像真实用户；不要编号、不要解释、不要使用“机器人、AI、测试、用户、游客”等词；不要网址、邮箱或表情。', $batch_count );
			$meta        = array();
			$text        = '';

			if ( 'compatible' === $options['ai_provider'] ) {
				$text = self::request_compatible_text( $prompt, $options, 'nickname', $meta );
			} elseif ( 'gemini' === $options['ai_provider'] ) {
				$text = self::request_gemini_text( $prompt, $options, $meta );
			} elseif ( 'openrouter' === $options['ai_provider'] ) {
				$text = self::request_openrouter_text( $prompt, $options, 'nickname', $meta );
			}

			if ( '' === trim( (string) $text ) ) {
				if ( ! empty( $meta['error'] ) ) {
					self::add_activity_log( 'user', 'warning', 'AI 昵称生成失败，已使用本地抽象词库。', array( 'provider' => $meta['provider'] ?? '', 'model' => $meta['model'] ?? '', 'ai_error' => $meta['error'] ) );
				}
				break;
			}

			$names = array_merge( $names, self::parse_ai_nicknames( $text ) );
		}

		return array_slice( array_values( array_unique( array_filter( $names ) ) ), 0, $count );
	}

	private static function parse_ai_nicknames( $text ) {
		$items = preg_split( '/\r\n|\r|\n|,|，|、/', (string) $text );
		$names = array();
		foreach ( $items as $item ) {
			$name = self::clean_nickname( $item );
			if ( $name ) {
				$names[] = $name;
			}
		}

		return $names;
	}

	private static function clean_nickname( $name ) {
		$name = wp_strip_all_tags( (string) $name );
		$name = (string) preg_replace( ‘/^\s*[-*#\d一二三四五六七八九十]+[、.．)）:\-：\s]+/u’, ‘’, $name );
		$name = trim( $name, “ \t\n\r\0\x0B\”’””’’`·.-_” );
		$name = (string) preg_replace( ‘/(机器人|AI|测试|用户|游客)/iu’, ‘’, $name );
		$name = (string) preg_replace( ‘/[^\p{Han}A-Za-z0-9_\-\s]/u’, ‘’, $name );
		$name = trim( (string) preg_replace( ‘/\s+/u’, ‘’, $name ) );
		if ( ‘’ === $name ) {
			return ‘’;
		}
		if ( function_exists( ‘mb_strlen’ ) && mb_strlen( $name, ‘UTF-8’ ) > 14 ) {
			$name = mb_substr( $name, 0, 14, ‘UTF-8’ );
		}

		return sanitize_text_field( $name );
	}

	private static function random_nickname() {
		$name = self::random_item( array( '云隙', '折光', '回声', '浮标', '墨点', '星屿', '雨栈', '风页', '浅层', '弦外', '远屏', '轻舟', '蓝调', '灰阶', '竹影', '雾线', '慢频', '拾页', '半径', '溪午', '松间', '白昼', '纸航', '微尘', '北窗', '南页', '青栈', '余温', '月阶', '空集' ) ) . self::random_item( array( '随记', '小站', '片段', '注脚', '回廊', '低语', '坐标', '备忘', '片语', '札记', '一角', '浮层', '清单', '漫游', '笔记', '侧影', '停靠', '长风', '短章', '声纹', '余页', '散步', '微光', '留白', '航线', '折页', '行间' ) );
		return wp_rand( 1, 100 ) <= 45 ? $name . wp_rand( 10, 99 ) : $name;
	}

	private static function random_signature() {
		return self::random_item( array( '随便看看，认真记录。', '记录一点小想法。', '慢慢学习，慢慢折腾。', '喜欢清爽一点的社区氛围。', '这里留下一点个人资料。' ) );
	}

	private static function random_avatar_url( $seed ) {
		$style = self::random_item( array( 'adventurer', 'avataaars', 'bottts', 'initials', 'lorelei', 'micah', 'notionists' ) );
		return 'https://api.dicebear.com/7.x/' . rawurlencode( $style ) . '/svg?seed=' . rawurlencode( 'zibi-name-' . $seed );
	}

	private static function random_plate_id() {
		if ( ! post_type_exists( 'plate' ) ) {
			return 0;
		}
		$plates = get_posts( array( 'post_type' => 'plate', 'post_status' => 'publish', 'posts_per_page' => 50, 'fields' => 'ids' ) );
		return empty( $plates ) ? 0 : absint( $plates[ array_rand( $plates ) ] );
	}

	private static function random_term_id( $taxonomy ) {
		$ids = self::random_term_ids( $taxonomy, 1 );
		return empty( $ids ) ? 0 : $ids[0];
	}

	private static function random_term_ids( $taxonomy, $limit ) {
		if ( ! taxonomy_exists( $taxonomy ) ) {
			return array();
		}
		$terms = get_terms( array( 'taxonomy' => $taxonomy, 'hide_empty' => false, 'number' => 50, 'fields' => 'ids' ) );
		if ( is_wp_error( $terms ) || empty( $terms ) ) {
			return array();
		}
		shuffle( $terms );
		return array_slice( array_map( 'absint', $terms ), 0, absint( $limit ) );
	}

	private static function comment_templates() {
		return array(
			'question'   => array( '《%s》这个思路挺清楚，我想问下如果换一种场景是不是也适用？', '看完《%s》有个疑问，这里面提到的步骤有没有更简单的做法？' ),
			'agree'      => array( '《%s》这篇说得挺贴近实际，尤其是中间那段我比较认同。', '这个观点我也有类似感受，《%s》整理得比较清楚。' ),
			'supplement' => array( '《%s》里这个方向不错，感觉还可以补充一些实际案例会更完整。', '读完《%s》后想到一点，如果加上对比说明可能会更直观。' ),
			'discussion' => array( '《%s》这个话题挺适合展开聊聊，先收藏后面继续看。', '这篇《%s》读起来比较顺，适合社区里的讨论氛围。' ),
		);
	}

	private static function random_line( $text ) {
		$lines = array_values( array_filter( array_map( 'trim', preg_split( '/\r\n|\r|\n/', $text ) ) ) );
		return empty( $lines ) ? '' : $lines[ array_rand( $lines ) ];
	}

	private static function random_item( array $items ) {
		return $items[ array_rand( $items ) ];
	}

	private static function stats() {
		$cached = get_transient( self::STATS_CACHE_KEY );

		if ( false !== $cached && is_array( $cached ) ) {
			return $cached;
		}

		$today = current_time( 'Y-m-d' );
		$today_comments = get_comments( array( 'count' => true, 'status' => 'all', 'meta_key' => self::DATE_META_KEY, 'meta_value' => $today ) );
		$stats = array(
			'users'          => count( self::get_generated_users( self::MAX_USER_POOL_SIZE ) ),
			'post_comments'  => self::generated_comment_count_by_type( 'post' ),
			'forum_comments' => self::generated_comment_count_by_type( 'forum_post' ),
			'forum_posts'    => self::count_generated_forum_posts(),
			'today_total'    => absint( $today_comments ) + self::generated_forum_post_count( $today ),
			'failures'       => self::activity_log_count( 'error' ),
		);

		set_transient( self::STATS_CACHE_KEY, $stats, self::STATS_CACHE_DURATION );

		return $stats;
	}

	private static function activity_logs( $limit = 120 ) {
		$limit = absint( $limit );
		$limit = $limit > 0 ? $limit : self::MAX_ACTIVITY_LOGS;
		$logs  = get_option( self::LOG_OPTION_KEY, array() );

		if ( ! is_array( $logs ) ) {
			return array();
		}

		$normalized = array();
		foreach ( $logs as $log ) {
			if ( ! is_array( $log ) ) {
				continue;
			}

			$normalized[] = array(
				'time'    => sanitize_text_field( $log['time'] ?? '' ),
				'type'    => sanitize_key( $log['type'] ?? 'system' ),
				'status'  => sanitize_key( $log['status'] ?? 'info' ),
				'message' => wp_strip_all_tags( (string) ( $log['message'] ?? '' ) ),
				'context' => is_array( $log['context'] ?? null ) ? $log['context'] : array(),
			);
		}

		return array_slice( $normalized, 0, $limit );
	}

	private static function add_activity_log( $type, $status, $message, array $context = array() ) {
		$logs = self::activity_logs( self::MAX_ACTIVITY_LOGS );
		array_unshift(
			$logs,
			array(
				'time'    => current_time( 'mysql' ),
				'type'    => sanitize_key( $type ),
				'status'  => sanitize_key( $status ),
				'message' => wp_strip_all_tags( (string) $message ),
				'context' => self::sanitize_log_context( $context ),
			)
		);

		update_option( self::LOG_OPTION_KEY, array_slice( $logs, 0, self::MAX_ACTIVITY_LOGS ), false );
		delete_transient( self::STATS_CACHE_KEY );
	}

	private static function sanitize_log_context( array $context ) {
		$clean = array();
		foreach ( $context as $key => $value ) {
			if ( is_array( $value ) ) {
				$value = implode( ',', array_map( 'strval', $value ) );
			} elseif ( is_object( $value ) ) {
				$value = method_exists( $value, '__toString' ) ? (string) $value : wp_json_encode( $value );
			} else {
				$value = (string) $value;
			}

			$clean[ sanitize_key( $key ) ] = sanitize_text_field( $value );
		}

		return $clean;
	}

	private static function activity_log_count( $status = '' ) {
		$logs = self::activity_logs( 120 );
		if ( '' === $status ) {
			return count( $logs );
		}

		$status = sanitize_key( $status );
		$count  = 0;
		foreach ( $logs as $log ) {
			if ( $status === ( $log['status'] ?? '' ) ) {
				$count++;
			}
		}

		return $count;
	}

	private static function activity_type_label( $type ) {
		$labels = array(
			'user'    => '用户池',
			'comment' => '评论',
			'forum'   => '社区帖',
			'ai'      => 'AI',
			'cron'    => '计划任务',
			'system'  => '系统',
		);

		return $labels[ sanitize_key( $type ) ] ?? '其他';
	}

	private static function activity_status_label( $status ) {
		$labels = array(
			'error'   => '失败',
			'warning' => '提醒',
			'success' => '成功',
			'info'    => '信息',
		);

		return $labels[ sanitize_key( $status ) ] ?? '信息';
	}

	private static function format_log_context( $context ) {
		if ( ! is_array( $context ) || empty( $context ) ) {
			return '';
		}

		$labels = array(
			'comment_id'     => '评论 ID',
			'post_id'        => '帖子 ID',
			'target_post_id' => '目标内容 ID',
			'user_id'        => '用户 ID',
			'provider'       => 'AI 服务',
			'model'          => '模型',
			'ai_error'       => 'AI 错误',
			'title'          => '标题',
			'plate_id'       => '板块 ID',
			'topic_id'       => '话题 ID',
			'login'          => '登录名',
			'email'          => '邮箱',
		);
		$parts = array();
		foreach ( $context as $key => $value ) {
			if ( '' === $value || null === $value ) {
				continue;
			}
			$key = sanitize_key( $key );
			$parts[] = ( $labels[ $key ] ?? $key ) . ': ' . sanitize_text_field( (string) $value );
		}

		return implode( '；', $parts );
	}

	private static function log_comment_creation( $comment_id, $post_id, $user_id, array $meta ) {
		if ( 'ai' === ( $meta['source'] ?? '' ) ) {
			self::add_activity_log(
				'comment',
				'success',
				'评论已发布：AI 模型生成回复。',
				array(
					'comment_id'     => $comment_id,
					'target_post_id' => $post_id,
					'user_id'        => $user_id,
					'provider'       => $meta['provider'] ?? '',
					'model'          => $meta['model'] ?? '',
				)
			);
		} elseif ( ! empty( $meta['error'] ) ) {
			self::add_activity_log(
				'comment',
				'warning',
				'评论已发布：AI 调用失败，已使用备用模板。',
				array(
					'comment_id'     => $comment_id,
					'target_post_id' => $post_id,
					'user_id'        => $user_id,
					'provider'       => $meta['provider'] ?? '',
					'model'          => $meta['model'] ?? '',
					'ai_error'       => $meta['error'] ?? '',
				)
			);
		}
	}

	private static function log_forum_post_creation( $post_id, $user_id, array $meta ) {
		if ( 'ai' === ( $meta['source'] ?? '' ) ) {
			self::add_activity_log(
				'forum',
				'success',
				'社区帖已发布：AI 模型生成正文。',
				array(
					'post_id'  => $post_id,
					'user_id'  => $user_id,
					'provider' => $meta['provider'] ?? '',
					'model'    => $meta['model'] ?? '',
				)
			);
		} elseif ( ! empty( $meta['error'] ) ) {
			self::add_activity_log(
				'forum',
				'warning',
				'社区帖已发布：AI 调用失败，已使用备用模板。',
				array(
					'post_id'  => $post_id,
					'user_id'  => $user_id,
					'provider' => $meta['provider'] ?? '',
					'model'    => $meta['model'] ?? '',
					'ai_error' => $meta['error'] ?? '',
				)
			);
		}
	}

	private static function generated_comment_count_by_type( $post_type ) {
		global $wpdb;
		$sql = $wpdb->prepare(
			"SELECT COUNT(DISTINCT c.comment_ID)
			FROM {$wpdb->comments} c
			INNER JOIN {$wpdb->commentmeta} cm ON c.comment_ID = cm.comment_id
			INNER JOIN {$wpdb->posts} p ON c.comment_post_ID = p.ID
			WHERE cm.meta_key = %s
			AND cm.meta_value = '1'
			AND p.post_type = %s",
			self::COMMENT_META_KEY,
			$post_type
		);
		return absint( $wpdb->get_var( $sql ) );
	}

	private static function generated_forum_post_count( $date = '' ) {
		global $wpdb;
		if ( $date ) {
			$sql = $wpdb->prepare(
				"SELECT COUNT(DISTINCT p.ID)
				FROM {$wpdb->posts} p
				INNER JOIN {$wpdb->postmeta} pm1 ON p.ID = pm1.post_id AND pm1.meta_key = %s AND pm1.meta_value = '1'
				INNER JOIN {$wpdb->postmeta} pm2 ON p.ID = pm2.post_id AND pm2.meta_key = %s AND pm2.meta_value = %s
				WHERE p.post_type = 'forum_post'",
				self::POST_META_KEY,
				self::DATE_META_KEY,
				$date
			);
		} else {
			$sql = $wpdb->prepare(
				"SELECT COUNT(DISTINCT p.ID)
				FROM {$wpdb->posts} p
				INNER JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id
				WHERE p.post_type = 'forum_post'
				AND pm.meta_key = %s
				AND pm.meta_value = '1'",
				self::POST_META_KEY
			);
		}
		return absint( $wpdb->get_var( $sql ) );
	}

	private static function count_generated_forum_posts() {
		global $wpdb;
		$sql = $wpdb->prepare(
			"SELECT COUNT(DISTINCT p.ID)
			FROM {$wpdb->posts} p
			INNER JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id
			WHERE p.post_type = 'forum_post'
			AND pm.meta_key = %s
			AND pm.meta_value = '1'",
			self::POST_META_KEY
		);
		return absint( $wpdb->get_var( $sql ) );
	}

	private static function delete_generated_comments() {
		return self::delete_comments_by_ids( get_comments( array( 'status' => 'all', 'fields' => 'ids', 'meta_key' => self::COMMENT_META_KEY, 'number' => 0 ) ) );
	}

	private static function delete_generated_forum_posts() {
		return self::delete_posts_by_ids( wp_list_pluck( self::get_generated_forum_posts( 10000 ), 'ID' ) );
	}

	private static function delete_generated_users() {
		return self::delete_users_by_ids( wp_list_pluck( self::get_generated_users( 3000 ), 'ID' ) );
	}

	private static function delete_comments_by_ids( array $ids ) {
		$deleted = 0;
		foreach ( array_unique( array_map( 'absint', $ids ) ) as $id ) {
			if ( get_comment_meta( $id, self::COMMENT_META_KEY, true ) && wp_delete_comment( $id, true ) ) {
				$deleted++;
			}
		}
		if ( $deleted > 0 ) {
			delete_transient( self::STATS_CACHE_KEY );
		}
		return $deleted;
	}

	private static function delete_posts_by_ids( array $ids ) {
		$deleted = 0;
		foreach ( array_unique( array_map( 'absint', $ids ) ) as $id ) {
			if ( get_post_meta( $id, self::POST_META_KEY, true ) && wp_delete_post( $id, true ) ) {
				$deleted++;
			}
		}
		if ( $deleted > 0 ) {
			delete_transient( self::STATS_CACHE_KEY );
		}
		return $deleted;
	}

	private static function delete_users_by_ids( array $ids ) {
		if ( empty( $ids ) ) {
			return 0;
		}
		require_once ABSPATH . 'wp-admin/includes/user.php';
		$deleted = 0;
		foreach ( array_unique( array_map( 'absint', $ids ) ) as $id ) {
			if ( get_user_meta( $id, self::USER_META_KEY, true ) && wp_delete_user( $id ) ) {
				$deleted++;
			}
		}
		if ( $deleted > 0 ) {
			delete_transient( self::STATS_CACHE_KEY );
		}
		return $deleted;
	}

	private static function next_run_text() {
		$timestamp = wp_next_scheduled( self::CRON_HOOK );
		return $timestamp ? date_i18n( 'Y-m-d H:i:s', $timestamp ) : '未计划';
	}
}
