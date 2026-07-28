<?php
/**
 * Focused Castbox public-comment parser test.
 *
 * @package MRN_Podcaster
 */

define( 'ABSPATH', __DIR__ );
define( 'MRNP_VERSION', '0.2.5' );

if ( ! function_exists( '__' ) ) {
	function __( string $text ): string {
		return $text;
	}
}
if ( ! function_exists( 'sanitize_text_field' ) ) {
	function sanitize_text_field( string $text ): string {
		return trim( strip_tags( $text ) );
	}
}
if ( ! function_exists( 'wp_kses_post' ) ) {
	function wp_kses_post( string $text ): string {
		return strip_tags( $text, '<br><em><strong>' );
	}
}

require_once dirname( __DIR__ ) . '/includes/class-comment-importer.php';

/**
 * Fail loudly without depending on the runtime zend.assertions setting.
 *
 * @param bool   $condition Assertion result.
 * @param string $message Failure detail.
 * @return void
 */
function mrnp_test_expect( bool $condition, string $message ): void {
	if ( ! $condition ) {
		throw new RuntimeException( $message );
	}
}

$html = <<<'HTML'
<!doctype html><html><body>
<div class="commentItem opacityinAnimate">
	<h3 class="commentItemTitle"><div class="username ellipsis">مهری شجاعی</div></h3>
	<p data-id="castbox-101" class="commentItemDes">بسیار عالی و دلنشین</p>
	<div class="commentItemDate">Jul 22nd</div>
</div>
<div class="commentItem">
	<h3><div class="username">Mobina</div></h3>
	<p data-id="castbox-102" class="commentItemDes">سپاس از این خوانش کامل</p>
	<div class="commentItemDate">Mar 28th</div>
</div>
</body></html>
HTML;

$document = new DOMDocument();
$document->loadHTML( '<?xml encoding="utf-8" ?>' . $html, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING );
$importer = new MRN\Podcaster\Comment_Importer();
$method   = new ReflectionMethod( $importer, 'parse_castbox_comments' );
$comments = $method->invoke( $importer, $document, 'Castbox' );

mrnp_test_expect( 2 === count( $comments ), 'Expected two Castbox comments.' );
mrnp_test_expect( 'castbox-101' === $comments[0]['id'], 'Expected stable Castbox comment ID.' );
mrnp_test_expect( 'مهری شجاعی' === $comments[0]['author'], 'Expected Persian listener name.' );
mrnp_test_expect( 'بسیار عالی و دلنشین' === $comments[0]['text'], 'Expected comment body.' );
mrnp_test_expect( 'Castbox' === $comments[1]['source'], 'Expected Castbox source label.' );

echo "Comment importer tests passed.\n";
