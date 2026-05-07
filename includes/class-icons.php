<?php
/**
 * Inline SVG icon dictionary for option labels (Application, Materials,
 * Volume, Format, Budget). Matching is accent-insensitive.
 *
 * @package Bomedia\QuoteWizard
 */

declare( strict_types=1 );

namespace Bomedia\QuoteWizard;

defined( 'ABSPATH' ) || exit;

final class Icons {

	private const STROKE = 'fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"';

	private const SVGS = [
		'tshirt'    => '<path d="M16 4l4 3-2 4-2-1v10H8V10L6 11 4 7l4-3a4 4 0 008 0z"/>',
		'package'   => '<path d="M21 8l-9-5-9 5v8l9 5 9-5V8z"/><path d="M3 8l9 5 9-5"/><path d="M12 13v8"/>',
		'gift'      => '<rect x="3" y="8" width="18" height="4"/><path d="M12 8v13M3 12h18v9H3zM7 8a3 3 0 010-6c2 0 5 6 5 6M17 8a3 3 0 000-6c-2 0-5 6-5 6"/>',
		'factory'   => '<path d="M3 21V10l5 3V10l5 3V10l5 3v8H3z"/><path d="M7 17h2M11 17h2M15 17h2"/>',
		'home'      => '<path d="M3 11l9-8 9 8"/><path d="M5 10v10h14V10"/><path d="M10 20v-6h4v6"/>',
		'sign'      => '<path d="M4 4h12l4 4-4 4H4z"/><path d="M4 12v8"/>',

		'fabric'    => '<path d="M3 6c3 0 3 3 6 3s3-3 6-3 3 3 6 3"/><path d="M3 12c3 0 3 3 6 3s3-3 6-3 3 3 6 3"/><path d="M3 18c3 0 3 3 6 3"/>',
		'plastic'   => '<rect x="4" y="3" width="16" height="18" rx="2"/><path d="M8 7h8M8 11h6M8 15h8"/>',
		'wood'      => '<path d="M4 6c0 4 4 4 4 8s-4 4-4 8M10 6c0 4 4 4 4 8s-4 4-4 8M16 6c0 4 4 4 4 8s-4 4-4 8"/>',
		'metal'     => '<path d="M4 7l8-4 8 4-8 4z"/><path d="M4 12l8 4 8-4"/><path d="M4 17l8 4 8-4"/>',
		'glass'     => '<path d="M6 3h12l-2 11a4 4 0 01-8 0z"/><path d="M12 14v7"/><path d="M9 21h6"/>',
		'ceramic'   => '<path d="M7 4h10v3l-2 2v7l2 2v3H7v-3l2-2V9L7 7z"/>',
		'leather'   => '<path d="M5 4l3 3-1 4 4 2 5-1 3 3-3 4-4-1-1 4-4-2 1-5-3-3z"/>',
		'cardboard' => '<path d="M3 7h18v14H3z"/><path d="M3 7l3-3h12l3 3"/><path d="M9 10v6"/>',
		'paper'     => '<path d="M6 3h9l4 4v14H6z"/><path d="M14 3v5h5"/>',

		'volume-low'  => '<circle cx="12" cy="12" r="3"/>',
		'volume-mid'  => '<circle cx="12" cy="12" r="3"/><circle cx="12" cy="12" r="7"/>',
		'volume-high' => '<circle cx="12" cy="12" r="3"/><circle cx="12" cy="12" r="6"/><circle cx="12" cy="12" r="9"/>',

		'format-small'  => '<rect x="9" y="6" width="6" height="12" rx="1"/>',
		'format-medium' => '<rect x="6" y="5" width="12" height="14" rx="1"/>',
		'format-large'  => '<rect x="3" y="4" width="18" height="16" rx="1"/>',

		'budget-low'    => '<rect x="3" y="7" width="18" height="10" rx="2"/><path d="M12 11v2M8 11v2M16 11v2"/>',
		'budget-mid'    => '<circle cx="12" cy="12" r="9"/><path d="M9 9h6M9 12h6M11 8v8"/>',
		'budget-high'   => '<path d="M3 16l5-8 4 4 5-7 4 6"/><path d="M3 20h18"/>',

		'help' => '<circle cx="12" cy="12" r="9"/><path d="M9.5 9a2.5 2.5 0 015 0c0 1-1 1.5-2 2.2-.5.4-1 .9-1 1.6"/><circle cx="12" cy="17" r="0.6" fill="currentColor"/>',
	];

	/**
	 * Maps text to icon slug. Order matters: first match wins.
	 *
	 * @return array list of [needles[], icon_slug]
	 */
	private static function map(): array {
		return [
			[ [ 'textil', 'moda', 'ropa', 'camiseta', 'tshirt', 't-shirt', 'apparel', 'fashion', 'clothing' ], 'tshirt' ],
			[ [ 'packaging', 'embalaje', 'caja', 'box', 'package', 'verpackung', 'embalagem' ], 'package' ],
			[ [ 'promocional', 'regalo', 'merchandising', 'gift', 'promo', 'cadeau', 'geschenk' ], 'gift' ],
			[ [ 'industrial', 'tecnico', 'engineering', 'industriel', 'industrie' ], 'factory' ],
			[ [ 'decoracion', 'interior', 'home', 'decor', 'casa', 'maison' ], 'home' ],
			[ [ 'senalitica', 'senaletica', 'rotulacion', 'cartel', 'sign', 'signage', 'insegna' ], 'sign' ],

			[ [ 'algodon', 'cotton', 'poliester', 'polyester', 'tela', 'tejido', 'fabric' ], 'fabric' ],
			[ [ 'pvc', 'acrilico', 'metacrilato', 'plexi', 'plastico', 'plastic', 'kunststoff' ], 'plastic' ],
			[ [ 'madera', 'wood', 'bois', 'holz', 'madeira' ], 'wood' ],
			[ [ 'metal', 'acero', 'aluminio', 'aluminium', 'steel' ], 'metal' ],
			[ [ 'vidrio', 'cristal', 'glass', 'verre', 'glas', 'vetro' ], 'glass' ],
			[ [ 'ceramica', 'ceramic', 'porcelana' ], 'ceramic' ],
			[ [ 'cuero', 'piel', 'leather', 'cuir', 'leder', 'couro' ], 'leather' ],
			[ [ 'carton', 'cardboard', 'cartone', 'pappe' ], 'cardboard' ],
			[ [ 'papel', 'paper', 'papier', 'carta' ], 'paper' ],

			// Volume buckets — try to detect ranges by digits.
			[ [ '<100', '100-500', '<500', 'menos', '<' ], 'volume-low' ],
			[ [ '500-2000', 'medio', 'medium' ], 'volume-mid' ],
			[ [ '>2000', '+2000', '>5000', 'high', 'alto' ], 'volume-high' ],

			// Format.
			[ [ 'a4', 'a5', 'small', 'pequeño', 'pequeno' ], 'format-small' ],
			[ [ 'a3', 'a2', 'medium', 'medio' ], 'format-medium' ],
			[ [ 'a1', 'a0', '60', '90', 'large', 'grande', 'mayor' ], 'format-large' ],

			// Budget.
			[ [ 'hasta', 'until', 'low', 'bajo', '5.000' ], 'budget-low' ],
			[ [ '15.000', '15-40', 'medio', 'mid' ], 'budget-mid' ],
			[ [ '40.000', '+40', 'high', 'alto', 'mas de' ], 'budget-high' ],
		];
	}

	public static function for_label( string $label ): string {
		$norm = self::normalize( $label );
		foreach ( self::map() as [ $needles, $slug ] ) {
			foreach ( $needles as $n ) {
				if ( '' !== $n && false !== strpos( $norm, self::normalize( $n ) ) ) {
					return self::svg( $slug );
				}
			}
		}
		return self::svg( 'help' );
	}

	public static function svg( string $slug ): string {
		$body = self::SVGS[ $slug ] ?? self::SVGS['help'];
		return '<svg viewBox="0 0 24 24" width="40" height="40" ' . self::STROKE . ' aria-hidden="true">' . $body . '</svg>';
	}

	private static function normalize( string $s ): string {
		$s = strtolower( $s );
		if ( function_exists( 'remove_accents' ) ) {
			$s = remove_accents( $s );
		}
		return $s;
	}
}
