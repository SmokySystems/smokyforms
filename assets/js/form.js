/**
 * SmokyForms front end: checks fields in the browser, sends the form without reloading the page
 * and shows the answer. Without JavaScript the form still works through admin-post.php.
 */
( function () {
	'use strict';

	var text = window.smokyformsText || {};

	function fieldOf( el ) {
		return el.closest( '.smokyforms-field' );
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
		if ( box ) {
			box.textContent = message || '';
			box.hidden = ! message;
		}
	}

	function check( field ) {
		var input = field.querySelector( 'input:not([type="hidden"]), select, textarea' ),
			required, value, checked, i, boxes;
		if ( ! input || field.classList.contains( 'smokyforms-type-calculation' ) ) {
			return '';
		}
		required = !! field.querySelector( '[aria-required="true"], [required]' );
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

	function submit( form, event ) {
		var fields = form.querySelectorAll( '.smokyforms-field' ),
			first = null, i, msg, button, label, data;

		event.preventDefault();
		for ( i = 0; i < fields.length; i++ ) {
			msg = check( fields[ i ] );
			showError( fields[ i ], msg );
			if ( msg && ! first ) {
				first = fields[ i ];
			}
		}
		if ( first ) {
			setMessage( form, 'error', '<p>' + escapeHtml( text.check ) + '</p>' );
			( first.querySelector( 'input, select, textarea' ) || first ).focus();
			return;
		}

		button = form.querySelector( '.smokyforms-button' );
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
				var key, field, focus = null;
				if ( res && res.ok ) {
					if ( res.redirect ) {
						window.location.href = res.redirect;
						return;
					}
					form.reset();
					setMessage( form, 'success', res.message || '' );
					var box = form.querySelector( '.smokyforms-message' );
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
					( focus.querySelector( 'input, select, textarea' ) || focus ).focus();
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
		form.addEventListener( 'submit', function ( e ) {
			submit( form, e );
		} );
		// Clear an error as soon as the visitor fixes the field.
		form.addEventListener( 'change', function ( e ) {
			var field = fieldOf( e.target );
			if ( field && field.classList.contains( 'smokyforms-has-error' ) ) {
				showError( field, check( field ) );
			}
		} );
		form.addEventListener( 'input', function ( e ) {
			var field = fieldOf( e.target );
			if ( field && field.classList.contains( 'smokyforms-has-error' ) && ! check( field ) ) {
				showError( field, '' );
			}
		} );
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
