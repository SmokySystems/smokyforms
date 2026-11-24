/**
 * SmokyForms front end: checks fields in the browser, conditional logic, steps,
 * repeating groups, and sends the form without reloading the page.
 * Without JavaScript the form still works through admin-post.php (all steps and fields shown).
 */
( function () {
	'use strict';

	var text = window.smokyformsText || {};

	function fmt( s ) {
		var args = Array.prototype.slice.call( arguments, 1 ), n = 0;
		return String( s || '' ).replace( /%(?:(\d)\$)?s/g, function ( m, pos ) {
			var v = pos ? args[ pos - 1 ] : args[ n++ ];
			return v === undefined ? '' : v;
		} );
	}

	function fieldOf( el ) {
		return el.closest( '.smokyforms-field' );
	}

	function logicHidden( el ) {
		return !! el.closest( '[data-logic][hidden]' );
	}

	function typeOf( field ) {
		var m = field && /smokyforms-type-([a-z_]+)/.exec( field.className );
		return m ? m[ 1 ] : '';
	}

	function changed( el ) {
		el.dispatchEvent( new Event( 'change', { bubbles: true } ) );
	}

	function showError( field, message ) {
		var box, inputs, i;
		if ( ! field ) {
			return;
		}
		box = field.querySelector( '.smokyforms-error' );
		inputs = field.querySelectorAll( 'input, select, textarea, fieldset' );
		field.classList.toggle( 'smokyforms-has-error', !! message );
		for ( i = 0; i < inputs.length; i++ ) {
			if ( message ) {
				inputs[ i ].setAttribute( 'aria-invalid', 'true' );
			} else {
				inputs[ i ].removeAttribute( 'aria-invalid' );
			}
		}
		// The error box of a group is its last one (rows have their own).
		if ( typeOf( field ) === 'repeater' ) {
			box = field.querySelector( ':scope > .smokyforms-error' );
		}
		if ( box ) {
			box.textContent = message || '';
			box.hidden = ! message;
		}
	}

	function rowEmpty( row ) {
		var items = row.querySelectorAll( 'input, select, textarea' ), i;
		for ( i = 0; i < items.length; i++ ) {
			if ( String( items[ i ].value || '' ).trim() ) {
				return false;
			}
		}
		return true;
	}

	function checkFiles( input, required ) {
		var files = input.files || [], max = parseInt( input.getAttribute( 'data-max-files' ), 10 ) || 1,
			size = parseInt( input.getAttribute( 'data-max-size' ), 10 ) || 0,
			exts = String( input.getAttribute( 'data-extensions' ) || '' ).split( ',' ), i, ext;
		if ( ! files.length ) {
			return required ? text.required : '';
		}
		if ( files.length > max ) {
			return input.getAttribute( 'data-too-many' ) || '';
		}
		for ( i = 0; i < files.length; i++ ) {
			ext = files[ i ].name.split( '.' ).pop().toLowerCase();
			if ( exts.indexOf( ext ) === -1 ) {
				return fmt( text.badType, files[ i ].name );
			}
			if ( size && files[ i ].size > size ) {
				return fmt( text.tooBig, files[ i ].name, input.getAttribute( 'data-max-mb' ) );
			}
		}
		return '';
	}

	function check( field ) {
		var type = typeOf( field ), input, required, value, checked, i, boxes, row, rows, filled;
		if ( logicHidden( field ) || type === 'calculation' ) {
			return '';
		}
		required = field.getAttribute( 'data-required' ) === '1' || ( type !== 'repeater' && !! field.querySelector( '[aria-required="true"], [required]' ) );
		if ( type === 'repeater' ) {
			rows = field.querySelectorAll( '.smokyforms-row' );
			filled = 0;
			for ( i = 0; i < rows.length; i++ ) {
				if ( ! rowEmpty( rows[ i ] ) ) {
					filled++;
				}
			}
			return required && ! filled ? text.required : '';
		}
		if ( type === 'signature' ) {
			input = field.querySelector( 'input[data-signature]' );
			return required && input && ! input.value ? text.required : '';
		}
		row = field.closest( '.smokyforms-row' );
		if ( row && rowEmpty( row ) ) {
			return '';
		}
		input = field.querySelector( 'input:not([type="hidden"]), select, textarea' );
		if ( ! input ) {
			return '';
		}
		if ( input.type === 'file' ) {
			return checkFiles( input, required );
		}
		if ( input.type === 'radio' || input.type === 'checkbox' ) {
			boxes = field.querySelectorAll( 'input[type="radio"], input[type="checkbox"]' );
			checked = false;
			for ( i = 0; i < boxes.length; i++ ) {
				if ( boxes[ i ].checked ) {
					checked = true;
				}
			}
			return required && ! checked ? text.required : '';
		}
		value = String( input.value || '' ).trim();
		if ( ! value ) {
			return required ? text.required : '';
		}
		if ( input.type === 'email' && ! /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test( value ) ) {
			return text.email;
		}
		if ( input.type === 'number' && input.validity && input.validity.badInput ) {
			return text.number;
		}
		return '';
	}

	/* Conditional logic (same rules as includes/Logic.php). */

	function answers( form, id ) {
		var items = form.querySelectorAll( '[name="smokyforms[' + id + ']"], [name="smokyforms[' + id + '][]"], [name="smokyforms_files[' + id + '][]"]' ),
			out = [], i, j, el;
		for ( i = 0; i < items.length; i++ ) {
			el = items[ i ];
			if ( el.disabled ) {
				continue;
			}
			if ( el.type === 'radio' || el.type === 'checkbox' ) {
				if ( el.checked ) {
					out.push( el.value );
				}
			} else if ( el.type === 'file' ) {
				for ( j = 0; j < ( el.files || [] ).length; j++ ) {
					out.push( el.files[ j ].name );
				}
			} else if ( String( el.value ).trim() !== '' ) {
				out.push( String( el.value ) );
			}
		}
		// A repeating group counts as answered when one of its rows is.
		if ( ! items.length ) {
			items = form.querySelectorAll( '.smokyforms-field[data-field="' + id + '"] .smokyforms-row' );
			for ( i = 0; i < items.length; i++ ) {
				if ( ! rowEmpty( items[ i ] ) ) {
					out.push( '1' );
				}
			}
		}
		return out;
	}

	function choiceNumber( form, id, list ) {
		var sum = 0, items = form.querySelectorAll( '[name="smokyforms[' + id + ']"], [name="smokyforms[' + id + '][]"]' ), i, opts, j;
		for ( i = 0; i < items.length; i++ ) {
			if ( items[ i ].tagName === 'SELECT' ) {
				opts = items[ i ].options;
				for ( j = 0; j < opts.length; j++ ) {
					if ( opts[ j ].selected && list.indexOf( opts[ j ].value ) !== -1 ) {
						sum += Number( opts[ j ].getAttribute( 'data-calc' ) ) || 0;
					}
				}
			} else if ( items[ i ].checked ) {
				sum += Number( items[ i ].getAttribute( 'data-calc' ) ) || 0;
			}
		}
		return sum;
	}

	function same( list, want ) {
		var i, a, b;
		want = String( want ).trim();
		if ( ! list.length ) {
			return want === '';
		}
		for ( i = 0; i < list.length; i++ ) {
			a = String( list[ i ] ).trim();
			b = want;
			if ( ( isFinite( parseFloat( a.replace( ',', '.' ) ) ) && /^-?[0-9.,]+$/.test( a ) && /^-?[0-9.,]+$/.test( b ) && parseFloat( a.replace( ',', '.' ) ) === parseFloat( b.replace( ',', '.' ) ) ) || a.toLowerCase() === b.toLowerCase() ) {
				return true;
			}
		}
		return false;
	}

	function test( form, id, list, op, want ) {
		var field = form.querySelector( '.smokyforms-field[data-field="' + id + '"]' ), type = typeOf( field ), n, w, d;
		switch ( op ) {
			case 'empty':
				return ! list.length;
			case 'not_empty':
				return !! list.length;
			case 'gt':
			case 'lt':
				w = String( want ).trim().replace( ',', '.' );
				if ( ! list.length || w === '' || ! isFinite( Number( w ) ) ) {
					return false;
				}
				if ( [ 'number', 'calculation', 'rating', 'text' ].indexOf( type ) !== -1 || ! type ) {
					n = parseFloat( String( list[ 0 ] ).replace( ',', '.' ) ) || 0;
				} else if ( type === 'date' ) {
					d = /^(\d{4})-(\d{2})-(\d{2})$/.exec( list[ 0 ] );
					n = d ? Math.floor( Date.UTC( +d[ 1 ], d[ 2 ] - 1, +d[ 3 ] ) / 86400000 ) : 0;
				} else if ( type === 'file' || type === 'repeater' ) {
					n = list.length;
				} else {
					n = choiceNumber( form, id, list );
				}
				return op === 'gt' ? n > Number( w ) : n < Number( w );
			case 'contains':
				return want !== '' && list.join( ' ' ).toLowerCase().indexOf( String( want ).toLowerCase() ) !== -1;
			case 'is_not':
				return ! same( list, want );
			default:
				return same( list, want );
		}
	}

	function applyLogic( form, depth ) {
		var boxes = form.querySelectorAll( '.smokyforms-field[data-logic]' ), rules = [], hidden = {}, pass, i, j, changedAny = false, logic, any, hit, ok, r, hide, items;
		if ( ! boxes.length ) {
			return;
		}
		// Rules can use calculation results: make sure they are up to date, whichever script ran first.
		if ( ! depth && form.smokyformsCalc ) {
			form.smokyformsCalc();
		}
		for ( i = 0; i < boxes.length; i++ ) {
			try {
				rules.push( JSON.parse( boxes[ i ].getAttribute( 'data-logic' ) ) );
			} catch ( e ) {
				rules.push( null );
			}
		}
		// Values of fields hidden now count as empty, so read them as if enabled and decide again.
		for ( pass = 0; pass <= boxes.length; pass++ ) {
			var moved = false;
			for ( i = 0; i < boxes.length; i++ ) {
				logic = rules[ i ];
				if ( ! logic ) {
					continue;
				}
				any = logic.match === 'any';
				hit = ! any;
				for ( j = 0; j < logic.rules.length; j++ ) {
					r = logic.rules[ j ];
					ok = test( form, r.field, hidden[ r.field ] ? [] : answers( form, r.field ), r.op, r.value );
					if ( any && ok ) {
						hit = true;
						break;
					}
					if ( ! any && ! ok ) {
						hit = false;
						break;
					}
				}
				hide = logic.action === 'show' ? ! hit : hit;
				if ( hide !== !! hidden[ boxes[ i ].getAttribute( 'data-field' ) ] ) {
					moved = true;
					if ( hide ) {
						hidden[ boxes[ i ].getAttribute( 'data-field' ) ] = true;
					} else {
						delete hidden[ boxes[ i ].getAttribute( 'data-field' ) ];
					}
				}
			}
			if ( ! moved ) {
				break;
			}
		}
		for ( i = 0; i < boxes.length; i++ ) {
			hide = !! hidden[ boxes[ i ].getAttribute( 'data-field' ) ];
			if ( hide !== boxes[ i ].hidden ) {
				changedAny = true;
			}
			boxes[ i ].hidden = hide;
			items = boxes[ i ].querySelectorAll( 'input, select, textarea' );
			for ( j = 0; j < items.length; j++ ) {
				if ( hide && ! items[ j ].disabled ) {
					items[ j ].disabled = true;
					items[ j ].setAttribute( 'data-logic-off', '1' );
				} else if ( ! hide && items[ j ].getAttribute( 'data-logic-off' ) ) {
					items[ j ].disabled = false;
					items[ j ].removeAttribute( 'data-logic-off' );
				}
			}
			if ( hide ) {
				showError( boxes[ i ], '' );
			}
		}
		if ( changedAny && ( depth || 0 ) < 5 ) {
			if ( form.smokyformsCalc ) {
				form.smokyformsCalc();
			}
			applyLogic( form, ( depth || 0 ) + 1 );
		}
		if ( changedAny && form.smokyformsSteps ) {
			form.smokyformsSteps.refresh();
		}
	}

	/* Steps */

	function setupSteps( form ) {
		var steps = form.querySelectorAll( '.smokyforms-step' ), current = 0,
			prev = form.querySelector( '.smokyforms-prev' ), next = form.querySelector( '.smokyforms-next' ),
			send = form.querySelector( '.smokyforms-send' ), captcha = form.querySelector( '.smokyforms-captcha' ),
			progress = form.querySelector( '.smokyforms-progress' ), api;
		if ( ! steps.length ) {
			return;
		}

		function usable( i ) {
			var fields = steps[ i ].querySelectorAll( '.smokyforms-fields > .smokyforms-field' ), j;
			for ( j = 0; j < fields.length; j++ ) {
				if ( ! fields[ j ].hidden ) {
					return true;
				}
			}
			return false;
		}
		function find( from, dir ) {
			var i;
			for ( i = from; i >= 0 && i < steps.length; i += dir ) {
				if ( usable( i ) ) {
					return i;
				}
			}
			return -1;
		}
		function show( i, focus ) {
			var j, before = find( i - 1, -1 ), after = find( i + 1, 1 ), list = [], pos;
			current = i;
			for ( j = 0; j < steps.length; j++ ) {
				steps[ j ].hidden = j !== i;
				if ( usable( j ) ) {
					list.push( j );
				}
			}
			prev.hidden = before === -1;
			prev.textContent = steps[ i ].getAttribute( 'data-prev' ) || '';
			next.hidden = after === -1;
			next.textContent = steps[ i ].getAttribute( 'data-next' ) || '';
			send.hidden = after !== -1;
			if ( captcha ) {
				captcha.hidden = after !== -1;
			}
			if ( progress ) {
				pos = list.indexOf( i ) + 1;
				progress.hidden = false;
				progress.querySelector( '.smokyforms-progress-text' ).textContent = fmt( text.step, pos, list.length );
				progress.querySelector( '.smokyforms-progress-bar span' ).style.width = ( list.length ? ( pos / list.length ) * 100 : 0 ) + '%';
			}
			if ( focus ) {
				var target = steps[ i ].querySelector( '.smokyforms-step-title' ) || steps[ i ].querySelector( 'input:not([type="hidden"]), select, textarea' );
				if ( form.getBoundingClientRect().top < 0 ) {
					form.scrollIntoView( { behavior: 'smooth', block: 'start' } );
				}
				if ( target ) {
					if ( target.tagName === 'H3' ) {
						target.setAttribute( 'tabindex', '-1' );
					}
					target.focus( { preventScroll: true } );
				}
			}
		}
		function valid( i ) {
			var fields = steps[ i ].querySelectorAll( '.smokyforms-field' ), first = null, j, msg;
			for ( j = 0; j < fields.length; j++ ) {
				msg = check( fields[ j ] );
				showError( fields[ j ], msg );
				if ( msg && ! first ) {
					first = fields[ j ];
				}
			}
			if ( first ) {
				( first.querySelector( 'input:not([type="hidden"]), select, textarea' ) || first ).focus();
			}
			return ! first;
		}

		next.addEventListener( 'click', function () {
			var to = find( current + 1, 1 );
			if ( to !== -1 && valid( current ) ) {
				show( to, true );
			}
		} );
		prev.addEventListener( 'click', function () {
			var to = find( current - 1, -1 );
			if ( to !== -1 ) {
				show( to, true );
			}
		} );

		api = {
			last: function () {
				return find( current + 1, 1 ) === -1;
			},
			next: function () {
				next.click();
			},
			goTo: function ( el ) {
				var step = el && el.closest( '.smokyforms-step' );
				if ( step ) {
					show( parseInt( step.getAttribute( 'data-step' ), 10 ), false );
				}
			},
			refresh: function () {
				show( usable( current ) ? current : Math.max( find( current, 1 ), find( current, -1 ), 0 ), false );
			},
			reset: function () {
				show( Math.max( find( 0, 1 ), 0 ), false );
			},
		};
		form.smokyformsSteps = api;
		show( Math.max( find( 0, 1 ), 0 ), false );
	}

	/* Repeating groups */

	function setupRepeater( form, box ) {
		var rows = box.querySelector( '.smokyforms-rows' ), tpl = box.querySelector( 'template' ), add = box.querySelector( '.smokyforms-add-row' ),
			min = parseInt( box.getAttribute( 'data-min' ), 10 ) || 0, max = parseInt( box.getAttribute( 'data-max' ), 10 ) || 10,
			start = rows.children.length, next = parseInt( box.getAttribute( 'data-next' ), 10 ) || start;

		function update() {
			var count = rows.children.length, buttons = rows.querySelectorAll( '.smokyforms-remove-row' ), i;
			add.hidden = count >= max;
			for ( i = 0; i < buttons.length; i++ ) {
				buttons[ i ].hidden = count <= Math.max( 1, min );
			}
		}
		add.addEventListener( 'click', function () {
			var wrap = document.createElement( 'div' ), row, first;
			if ( rows.children.length >= max ) {
				return;
			}
			wrap.innerHTML = tpl.innerHTML.replace( /__i__/g, String( next++ ) );
			row = wrap.firstElementChild;
			rows.appendChild( row );
			update();
			first = row.querySelector( 'input, select, textarea' );
			if ( first ) {
				first.focus();
			}
			changed( rows );
		} );
		rows.addEventListener( 'click', function ( e ) {
			var btn = e.target.closest( '.smokyforms-remove-row' ), row, focus;
			if ( ! btn ) {
				return;
			}
			row = btn.closest( '.smokyforms-row' );
			focus = row.previousElementSibling || row.nextElementSibling;
			row.parentNode.removeChild( row );
			update();
			changed( rows );
			if ( focus ) {
				( focus.querySelector( 'input, select, textarea' ) || add ).focus();
			} else {
				add.focus();
			}
		} );
		form.addEventListener( 'reset', function () {
			while ( rows.children.length > start ) {
				rows.removeChild( rows.lastElementChild );
			}
			update();
		} );
		update();
	}

	/* Sending */

	function setMessage( form, state, html ) {
		var box = form.querySelector( '.smokyforms-message' );
		if ( ! box ) {
			return;
		}
		box.setAttribute( 'data-state', state );
		box.innerHTML = html;
		box.hidden = ! html;
	}

	function escapeHtml( s ) {
		var d = document.createElement( 'div' );
		d.textContent = s;
		return d.innerHTML;
	}

	function resetCaptcha() {
		try {
			if ( window.turnstile ) {
				window.turnstile.reset();
			}
			if ( window.grecaptcha ) {
				window.grecaptcha.reset();
			}
			if ( window.hcaptcha ) {
				window.hcaptcha.reset();
			}
		} catch ( e ) {}
	}

	function focusField( form, field ) {
		if ( form.smokyformsSteps ) {
			form.smokyformsSteps.goTo( field );
		}
		( field.querySelector( 'input:not([type="hidden"]), select, textarea, canvas' ) || field ).focus();
	}

	function submit( form, event ) {
		var fields = form.querySelectorAll( '.smokyforms-field' ),
			first = null, i, msg, button, label, data;

		event.preventDefault();
		// Enter on an earlier step moves to the next step.
		if ( form.smokyformsSteps && ! form.smokyformsSteps.last() ) {
			form.smokyformsSteps.next();
			return;
		}
		for ( i = 0; i < fields.length; i++ ) {
			msg = check( fields[ i ] );
			showError( fields[ i ], msg );
			if ( msg && ! first ) {
				first = fields[ i ];
			}
		}
		if ( first ) {
			setMessage( form, 'error', '<p>' + escapeHtml( text.check ) + '</p>' );
			focusField( form, first );
			return;
		}

		button = form.querySelector( '.smokyforms-send' );
		label = button ? button.textContent : '';
		if ( button ) {
			button.disabled = true;
			button.textContent = text.sending;
		}
		form.setAttribute( 'aria-busy', 'true' );
		data = new FormData( form );

		fetch( form.getAttribute( 'data-endpoint' ), {
			method: 'POST',
			body: data,
			credentials: 'same-origin',
		} )
			.then( function ( r ) {
				return r.json().catch( function () {
					return { ok: false, message: text.failed };
				} );
			} )
			.then( function ( res ) {
				var key, field, focus = null, box;
				if ( res && res.ok ) {
					if ( res.redirect ) {
						window.location.href = res.redirect;
						return;
					}
					form.reset();
					setMessage( form, 'success', res.message || '' );
					box = form.querySelector( '.smokyforms-message' );
					if ( box ) {
						box.setAttribute( 'tabindex', '-1' );
						box.focus();
					}
					resetCaptcha();
					return;
				}
				if ( res && res.errors ) {
					for ( key in res.errors ) {
						if ( Object.prototype.hasOwnProperty.call( res.errors, key ) ) {
							field = form.querySelector( '.smokyforms-field[data-field="' + key + '"]' );
							showError( field, res.errors[ key ] );
							if ( field && ! focus ) {
								focus = field;
							}
						}
					}
				}
				setMessage( form, 'error', '<p>' + escapeHtml( ( res && res.message ) || text.failed ) + '</p>' );
				if ( focus ) {
					focusField( form, focus );
				}
				resetCaptcha();
			} )
			.catch( function () {
				setMessage( form, 'error', '<p>' + escapeHtml( text.failed ) + '</p>' );
			} )
			.then( function () {
				form.removeAttribute( 'aria-busy' );
				if ( button ) {
					button.disabled = false;
					button.textContent = label;
				}
			} );
	}

	function setup( form ) {
		var groups = form.querySelectorAll( '.smokyforms-repeater' ), i, ref = form.querySelector( '[name="smokyforms_referrer"]' );
		if ( ref ) {
			ref.value = document.referrer || '';
		}
		for ( i = 0; i < groups.length; i++ ) {
			setupRepeater( form, groups[ i ] );
		}
		form.addEventListener( 'submit', function ( e ) {
			submit( form, e );
		} );
		form.addEventListener( 'change', function ( e ) {
			var field = fieldOf( e.target );
			applyLogic( form );
			// Clear an error as soon as the visitor fixes the field.
			if ( field && field.classList.contains( 'smokyforms-has-error' ) ) {
				showError( field, check( field ) );
			}
		} );
		form.addEventListener( 'input', function ( e ) {
			var field = fieldOf( e.target );
			applyLogic( form );
			if ( field && field.classList.contains( 'smokyforms-has-error' ) && ! check( field ) ) {
				showError( field, '' );
			}
		} );
		form.addEventListener( 'reset', function () {
			setTimeout( function () {
				applyLogic( form );
				if ( form.smokyformsSteps ) {
					form.smokyformsSteps.reset();
				}
			}, 0 );
		} );
		setupSteps( form );
		applyLogic( form );
	}

	function init() {
		var forms = document.querySelectorAll( 'form.smokyforms' ), i;
		for ( i = 0; i < forms.length; i++ ) {
			if ( window.fetch && window.FormData ) {
				setup( forms[ i ] );
			}
		}
	}
	if ( document.readyState === 'loading' ) {
		document.addEventListener( 'DOMContentLoaded', init );
	} else {
		init();
	}
}() );
