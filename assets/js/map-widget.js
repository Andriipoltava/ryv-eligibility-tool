/**
 * RYV_Map_Widget frontend enhancements: legend hover highlighting, a legend
 * show/hide toggle, and a small click popup - layered on top of MapSVG's own
 * rendering rather than replacing it, since MapSVG's own frontend has no
 * equivalent features for this categorical (non-choropleth) coloring mode.
 * See widgets/class-ryv-map-widget.php's render_map() for the localized
 * `ryvMapWidgetData` this script reads.
 */
(function () {
	'use strict';

	function onReady( fn ) {
		if ( document.readyState !== 'loading' ) {
			fn();
		} else {
			document.addEventListener( 'DOMContentLoaded', fn );
		}
	}

	function rgbToHex( color ) {
		if ( ! color ) {
			return '#000000';
		}
		if ( color.charAt( 0 ) === '#' ) {
			return color;
		}
		var m = color.match( /\d+(\.\d+)?/g );
		if ( ! m ) {
			return '#000000';
		}
		return '#' + [ m[0], m[1], m[2] ].map( function ( n ) {
			return ( '0' + parseInt( n, 10 ).toString( 16 ) ).slice( -2 );
		} ).join( '' );
	}

	function initWidget( widgetEl ) {
		wireLegendToggle( widgetEl );
		wireStatePicker( widgetEl );

		var mapContainer = widgetEl.querySelector( '.ryv-map-widget-map' );
		if ( ! mapContainer ) {
			return;
		}

		// MapSVG's shortcode HTML only contains a plain wrapper div at
		// server-render time - the `id="mapsvg-map-{id}"` element with the
		// shadow root attached is created by MapSVG's own async JS
		// bootstrap, so it doesn't exist yet at DOMContentLoaded. Poll for
		// the host itself, not just its readiness once found.
		var tries = 0;
		var timer = setInterval( function () {
			tries++;

			var mapHost = mapContainer.querySelector( '[id^="mapsvg-map-"]' );
			var root = mapHost ? mapHost.shadowRoot : null;
			var svg = root ? root.querySelector( 'svg' ) : null;
			var paths = svg ? svg.querySelectorAll( 'path[id]' ) : [];

			if ( svg && paths.length > 20 ) {
				clearInterval( timer );
				try {
					enhance( widgetEl, mapHost, root, paths );
				} catch ( err ) {
					window.console && console.error( '[RYV Map Widget] enhancement failed:', err );
				}
			} else if ( tries > 80 ) {
				clearInterval( timer );
			}
		}, 250 );
	}

	function enhance( widgetEl, mapHost, root, paths ) {
		injectHighlightStyles( root );
		applyStripes( root, paths );
		wireLegendHover( widgetEl, paths );
		wireClickPopup( widgetEl, mapHost, root );
	}


	/**
	 * A state in several categories is drawn as its first category's fill with
	 * the others layered on top, in the order they were picked: 2nd = diagonal
	 * stripes, 3rd = horizontal stripes, 4th = vertical stripes, 5th = outline.
	 * MapSVG only fills a region with one flat status color, so the stripes are
	 * an SVG <pattern> defined in the map's own <svg>, applied through a
	 * stylesheet rule (an inline fill gets wiped by MapSVG's hover handling).
	 */
	function applyStripes( root, paths ) {
		var data = window.ryvMapWidgetData || {};
		var svg = root.querySelector( 'svg' );
		if ( ! svg ) {
			return;
		}

		var NS = 'http://www.w3.org/2000/svg';
		var defs = svg.querySelector( 'defs.ryv-stripe-defs' );
		if ( ! defs ) {
			defs = document.createElementNS( NS, 'defs' );
			defs.setAttribute( 'class', 'ryv-stripe-defs' );
			svg.insertBefore( defs, svg.firstChild );
		}

		function el( name, attrs ) {
			var node = document.createElementNS( NS, name );
			Object.keys( attrs ).forEach( function ( key ) {
				node.setAttribute( key, attrs[ key ] );
			} );
			return node;
		}

		var rules = [];
		var overlays = [];

		paths.forEach( function ( path ) {
			var region = data[ path.id ];
			var layers = region && region.layers ? region.layers : [];
			if ( ! region || ! region.primary || ! layers.length ) {
				return;
			}
			path.classList.add( 'ryv-striped' );

			var diagonal = layers[ 0 ] || '';
			var horizontal = layers[ 1 ] || '';
			var vertical = layers[ 2 ] || '';
			var outline = layers[ 3 ] || '';

			if ( diagonal || horizontal || vertical ) {
				var id = 'ryv-stripe-' + [ region.primary, diagonal, horizontal, vertical ].join( '' ).replace( /[^a-z0-9]/gi, '' );
				if ( ! defs.querySelector( '#' + id ) ) {
					var pattern = el( 'pattern', { id: id, patternUnits: 'userSpaceOnUse', width: 12, height: 12 } );
					pattern.appendChild( el( 'rect', { width: 12, height: 12, fill: region.primary } ) );
					if ( diagonal ) {
						// Two polygons tile one 45-degree band across the cell.
						pattern.appendChild( el( 'polygon', { points: '0,6 6,0 12,0 0,12', fill: diagonal } ) );
						pattern.appendChild( el( 'polygon', { points: '12,6 12,12 6,12', fill: diagonal } ) );
					}
					if ( horizontal ) {
						pattern.appendChild( el( 'rect', { x: 0, y: 0, width: 12, height: 2.5, fill: horizontal } ) );
					}
					if ( vertical ) {
						pattern.appendChild( el( 'rect', { x: 0, y: 0, width: 2.5, height: 12, fill: vertical } ) );
					}
					defs.appendChild( pattern );
				}
				rules.push( 'path[id="' + path.id + '"]{fill:url(#' + id + ') !important;}' );
			}

			if ( outline ) {
				// A stroke on the path itself is half-covered by every
				// neighbour painted after it, so the outline is a copy of the
				// shape (no fill, no pointer events) appended last, which
				// paints above all states.
				overlays.push( { path: path, color: outline } );
			}
		} );

		overlays.forEach( function ( item ) {
			var copy = item.path.cloneNode( false );
			copy.removeAttribute( 'id' );
			copy.removeAttribute( 'style' );
			copy.setAttribute( 'class', 'ryv-outline-overlay' );
			copy.setAttribute( 'fill', 'none' );
			copy.setAttribute( 'stroke', item.color );
			copy.setAttribute( 'stroke-width', '3' );
			copy.setAttribute( 'stroke-linejoin', 'round' );
			copy.setAttribute( 'pointer-events', 'none' );
			item.path.parentNode.appendChild( copy );
		} );

		// An !important stylesheet rule survives MapSVG rewriting the path's own
		// inline `fill` on hover; the hover feedback for these states is a
		// slight fade instead.
		if ( rules.length ) {
			var style = document.createElement( 'style' );
			style.className = 'ryv-stripe-styles';
			style.textContent = rules.join( '' ) + 'path.ryv-striped:hover{opacity:.82;}';
			root.appendChild( style );
		}
	}

	// MapSVG renders its details view (the fullscreen modal on phones) in its
	// own shadow-root host, outside the map's - hiding the host hides it all.
	var MOBILE_CSS = '[id^="mapsvg-map-detailsView"] { display: none !important; }' +
		'@media (max-width: 767px) {' +
		'.mapsvg-filters-wrap, .mapsvg-toolbar-filters { display: none !important; }' +
		'}';

	function injectHighlightStyles( root ) {
		var style = document.createElement( 'style' );
		style.textContent =
			'.ryv-region-highlight { stroke: #111 !important; stroke-width: 2px !important; }' +
			'.ryv-region-dim { opacity: 0.35; }' +
			// MapSVG's own built-in region "details" popover
			// (.mapsvg-details-container) still opens on region click even
			// though wireClickPopup()'s capture-phase stopPropagation()
			// blocks MapSVG's *link-navigation* click handling - MapSVG
			// opens this one via its own internal event/state system, not
			// the native click bubble our listener intercepts. Left
			// visible, it stacks a second, unstyled "old" popup behind/
			// around RYV's own .ryv-map-widget-popup on every click, so it
			// is force-hidden here rather than relied on to stay closed.
			'.mapsvg-details-container { display: none !important; }' +
			'.ryv-outline-overlay { fill: none !important; pointer-events: none !important; }' +
			MOBILE_CSS;
		root.appendChild( style );

		// On phones MapSVG opens its details in a fullscreen modal that can
		// live outside the shadow root; hide it (and the address search) there too.
		if ( ! document.getElementById( 'ryv-map-mobile-css' ) ) {
			var docStyle = document.createElement( 'style' );
			docStyle.id = 'ryv-map-mobile-css';
			docStyle.textContent = MOBILE_CSS;
			document.head.appendChild( docStyle );
		}
	}

	function wireLegendToggle( widgetEl ) {
		var toggle = widgetEl.querySelector( '.ryv-map-widget-legend-toggle' );
		if ( ! toggle ) {
			return;
		}
		var listId = toggle.getAttribute( 'aria-controls' );
		var list = listId ? document.getElementById( listId ) : null;
		if ( ! list ) {
			return;
		}

		toggle.addEventListener( 'click', function () {
			var expanded = toggle.getAttribute( 'aria-expanded' ) !== 'false';
			toggle.setAttribute( 'aria-expanded', expanded ? 'false' : 'true' );
			list.hidden = expanded;
		} );
	}

	/**
	 * Turns the "Or pick from a list" state dropdown into a select2-style
	 * searchable combobox - the vanilla-JS equivalent of the main
	 * questionnaire's renderSearchableSelect()/.ryv-select2 (assets/js/src/
	 * renderer.js), reimplemented here rather than imported since this
	 * script isn't an ES module and has no build step of its own. Options
	 * come from the search input's own `data-options` attribute (see
	 * class-ryv-map-widget.php's render_picker()), not ryvMapWidgetData -
	 * that global is keyed by MapSVG region id for the click popup, not
	 * ordered/labeled the way this list needs.
	 */
	function wireStatePicker( widgetEl ) {
		var wrap = widgetEl.querySelector( '.ryv-map-widget-picker-select-wrap' );
		if ( ! wrap ) {
			return;
		}
		var input = wrap.querySelector( '.ryv-map-widget-picker-select' );
		var menu = wrap.querySelector( '.ryv-map-widget-picker-menu' );
		if ( ! input || ! menu ) {
			return;
		}

		var options = [];
		try {
			options = JSON.parse( input.getAttribute( 'data-options' ) || '[]' );
		} catch ( err ) {
			options = [];
		}

		var visibleOptions = [];
		var highlightedIndex = -1;

		function closeMenu() {
			menu.hidden = true;
			input.setAttribute( 'aria-expanded', 'false' );
			highlightedIndex = -1;
		}

		function openMenu( query ) {
			var q = query.trim().toLowerCase();
			visibleOptions = q ? options.filter( function ( option ) {
				return option.label.toLowerCase().indexOf( q ) !== -1;
			} ) : options;

			menu.innerHTML = '';

			if ( ! visibleOptions.length ) {
				var empty = document.createElement( 'div' );
				empty.className = 'ryv-map-widget-picker-empty';
				empty.textContent = ( window.ryvMapWidgetL10n && window.ryvMapWidgetL10n.noResults ) || 'No matches found';
				menu.appendChild( empty );
			} else {
				visibleOptions.forEach( function ( option, index ) {
					var item = document.createElement( 'div' );
					item.className = 'ryv-map-widget-picker-option' + ( index === highlightedIndex ? ' is-highlighted' : '' );
					item.textContent = option.label;
					item.setAttribute( 'role', 'option' );
					// mousedown, not click, so this commits before the
					// input's own blur handler below (which reverts an
					// unmatched typed value) has a chance to run.
					item.addEventListener( 'mousedown', function ( e ) {
						e.preventDefault();
						navigateTo( option );
					} );
					menu.appendChild( item );
				} );
			}

			menu.hidden = false;
			input.setAttribute( 'aria-expanded', 'true' );
		}

		function navigateTo( option ) {
			closeMenu();
			if ( option && option.value ) {
				window.location.href = option.value;
			}
		}

		input.addEventListener( 'focus', function () {
			openMenu( '' );
		} );
		input.addEventListener( 'input', function () {
			highlightedIndex = -1;
			openMenu( input.value );
		} );
		input.addEventListener( 'keydown', function ( e ) {
			if ( e.key === 'ArrowDown' ) {
				e.preventDefault();
				if ( menu.hidden ) {
					openMenu( input.value );
					return;
				}
				highlightedIndex = Math.min( highlightedIndex + 1, visibleOptions.length - 1 );
				openMenu( input.value );
			} else if ( e.key === 'ArrowUp' ) {
				e.preventDefault();
				highlightedIndex = Math.max( highlightedIndex - 1, 0 );
				openMenu( input.value );
			} else if ( e.key === 'Enter' ) {
				e.preventDefault();
				if ( ! menu.hidden && visibleOptions[ highlightedIndex ] ) {
					navigateTo( visibleOptions[ highlightedIndex ] );
				}
			} else if ( e.key === 'Escape' ) {
				closeMenu();
				input.value = '';
			}
		} );
		input.addEventListener( 'blur', function () {
			// Deferred so a mousedown-selected option above has already
			// navigated away by the time this would otherwise clear the
			// typed value back out.
			window.setTimeout( function () {
				if ( menu.hidden ) {
					return;
				}
				closeMenu();
				input.value = '';
			}, 100 );
		} );
	}

	function wireLegendHover( widgetEl, paths ) {
		var items = widgetEl.querySelectorAll( '.ryv-map-widget-legend-item' );
		if ( ! items.length ) {
			return;
		}

		items.forEach( function ( item ) {
			var swatch = item.querySelector( '.ryv-map-widget-legend-swatch' );
			if ( ! swatch ) {
				return;
			}

			// The striped swatch stands for every two-category state.
			if ( item.classList.contains( 'ryv-map-widget-legend-item--striped' ) ) {
				item.addEventListener( 'mouseenter', function () {
					paths.forEach( function ( path ) {
						path.classList.add( path.classList.contains( 'ryv-striped' ) ? 'ryv-region-highlight' : 'ryv-region-dim' );
					} );
				} );
				item.addEventListener( 'mouseleave', function () {
					paths.forEach( function ( path ) {
						path.classList.remove( 'ryv-region-highlight', 'ryv-region-dim' );
					} );
				} );
				return;
			}
			var hex = rgbToHex( getComputedStyle( swatch ).backgroundColor ).toLowerCase();

			item.addEventListener( 'mouseenter', function () {
				var data = window.ryvMapWidgetData || {};
				paths.forEach( function ( path ) {
					var region = data[ path.id ] || {};
					// A striped state belongs to each of its categories.
					var matches = ( region.primary && region.primary.toLowerCase() === hex )
						|| ( region.layers || [] ).some( function ( layer ) {
							return layer && layer.toLowerCase() === hex;
						} )
						|| rgbToHex( getComputedStyle( path ).fill ).toLowerCase() === hex;
					path.classList.add( matches ? 'ryv-region-highlight' : 'ryv-region-dim' );
				} );
			} );

			item.addEventListener( 'mouseleave', function () {
				paths.forEach( function ( path ) {
					path.classList.remove( 'ryv-region-highlight', 'ryv-region-dim' );
				} );
			} );
		} );
	}

	function wireClickPopup( widgetEl, mapHost, root ) {
		var mapEl = widgetEl.querySelector( '.ryv-map-widget-map' );
		if ( ! mapEl ) {
			return;
		}

		var data = window.ryvMapWidgetData || {};
		var labels = window.ryvMapWidgetL10n || {};

		var popup = document.createElement( 'div' );
		popup.className = 'ryv-map-widget-popup';
		popup.hidden = true;
		mapEl.appendChild( popup );

		function closePopup() {
			popup.hidden = true;
			// openPopup() below sets an inline `display: block` (to force
			// a layout so offsetWidth is measurable before `hidden` is
			// cleared) - an inline style always beats the UA stylesheet's
			// `[hidden] { display: none }`, so leaving it in place after
			// closing meant `hidden = true` alone no longer actually hid
			// the popup once it had been opened once. Clearing it here
			// lets `[hidden]` take effect again.
			popup.style.display = '';
		}

		function openPopup( regionId, clientX, clientY ) {
			var region = data[ regionId ];
			var title = ( region && region.title ) || regionId;

			popup.innerHTML = '';

			var closeBtn = document.createElement( 'button' );
			closeBtn.type = 'button';
			closeBtn.className = 'ryv-map-widget-popup-close';
			closeBtn.setAttribute( 'aria-label', labels.close || 'Close' );
			closeBtn.textContent = '×';
			closeBtn.addEventListener( 'click', closePopup );
			popup.appendChild( closeBtn );

			var h = document.createElement( 'div' );
			h.className = 'ryv-map-widget-popup-title';
			h.textContent = title;
			popup.appendChild( h );

			if ( region && region.description ) {
				var p = document.createElement( 'div' );
				p.className = 'ryv-map-widget-popup-desc';
				p.textContent = region.description;
				popup.appendChild( p );
			}

			if ( region && region.link ) {
				var a = document.createElement( 'a' );
				a.className = 'ryv-map-widget-popup-link';
				a.href = region.link;
				a.textContent = labels.learnMore || 'Learn More';
				popup.appendChild( a );
			}

			var wrapRect = mapEl.getBoundingClientRect();
			var x = clientX - wrapRect.left;
			var y = clientY - wrapRect.top;
			popup.style.display = 'block';
			var popupWidth = popup.offsetWidth || 240;
			x = Math.max( 8, Math.min( x, wrapRect.width - popupWidth - 8 ) );
			y = Math.max( 8, y - 12 );
			popup.style.left = x + 'px';
			popup.style.top = y + 'px';
			popup.hidden = false;
		}

		// Capture-phase listener on the shadow root, so this runs before
		// MapSVG's own per-region click handling gets a chance to (e.g.
		// navigating immediately via the region's own `link` field) -
		// stopPropagation() here during the capture phase prevents the
		// event from ever reaching that target-level handler.
		root.addEventListener( 'click', function ( e ) {
			var path = e.target.closest ? e.target.closest( 'path[id]' ) : null;
			if ( ! path ) {
				return;
			}
			e.stopPropagation();
			e.preventDefault();
			openPopup( path.id, e.clientX, e.clientY );
		}, true );

		// Touch screens: MapSVG reacts to touchstart/touchend itself and the
		// synthesized `click` doesn't reliably reach the listener above, so a
		// tap (a touch that barely moved) is handled directly here as well.
		var touchStart = null;
		root.addEventListener( 'touchstart', function ( e ) {
			var t = e.touches && e.touches[ 0 ];
			touchStart = t && e.touches.length === 1 ? { x: t.clientX, y: t.clientY } : null;
		}, { capture: true, passive: true } );
		root.addEventListener( 'touchend', function ( e ) {
			var t = e.changedTouches && e.changedTouches[ 0 ];
			var start = touchStart;
			touchStart = null;
			if ( ! t || ! start || Math.abs( t.clientX - start.x ) > 10 || Math.abs( t.clientY - start.y ) > 10 ) {
				return;
			}
			var target = document.elementFromPoint( t.clientX, t.clientY );
			var el = e.target && e.target.closest ? e.target : null;
			var path = el ? el.closest( 'path[id]' ) : null;
			if ( ! path && target && target.shadowRoot ) {
				var inner = target.shadowRoot.elementFromPoint( t.clientX, t.clientY );
				path = inner && inner.closest ? inner.closest( 'path[id]' ) : null;
			}
			if ( ! path ) {
				return;
			}
			e.stopPropagation();
			if ( e.cancelable ) {
				e.preventDefault();
			}
			openPopup( path.id, t.clientX, t.clientY );
		}, { capture: true, passive: false } );

		document.addEventListener( 'click', function ( e ) {
			if ( popup.hidden ) {
				return;
			}
			if ( ! popup.contains( e.target ) && e.target !== mapHost ) {
				closePopup();
			}
		} );
	}

	onReady( function () {
		document.querySelectorAll( '.ryv-map-widget' ).forEach( initWidget );
	} );
}());
