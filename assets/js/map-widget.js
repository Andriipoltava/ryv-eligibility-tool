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
		wireLegendHover( widgetEl, paths );
		wireClickPopup( widgetEl, mapHost, root );
	}

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
			'.mapsvg-details-container { display: none !important; }';
		root.appendChild( style );
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
			var hex = rgbToHex( getComputedStyle( swatch ).backgroundColor ).toLowerCase();

			item.addEventListener( 'mouseenter', function () {
				paths.forEach( function ( path ) {
					var pathHex = rgbToHex( getComputedStyle( path ).fill ).toLowerCase();
					path.classList.add( pathHex === hex ? 'ryv-region-highlight' : 'ryv-region-dim' );
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
				a.textContent = labels.learnMore || 'Learn more →';
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
