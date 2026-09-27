/* globals PCIO_VIS_SIGNUP */
( function () {
	'use strict';

	var CFG = window.PCIO_VIS_SIGNUP || {};
	var form, errorBox, successBox, submitBtn, spinner;

	function init() {
		form       = document.getElementById( 'pcio-ms-signup-form' );
		errorBox   = document.querySelector( '.pcio-ms-signup__error' );
		successBox = document.querySelector( '.pcio-ms-signup__success' );
		submitBtn  = form ? form.querySelector( '.pcio-ms-signup__submit' ) : null;
		spinner    = form ? form.querySelector( '.pcio-ms-signup__spinner' ) : null;

		if ( ! form ) {
			return;
		}

		form.addEventListener( 'submit', onSubmit );
	}

	function onSubmit( e ) {
		e.preventDefault();
		clearErrors();

		// Core validation.
		var errors = validateCore();

		// Let extensions add their own validation errors.
		if ( ! errors.length ) {
			var extEvent = new CustomEvent( 'pcioSignupValidate', {
				bubbles:    true,
				cancelable: false,
				detail:     { errors: errors, form: form },
			} );
			form.dispatchEvent( extEvent );
		}

		if ( errors.length ) {
			showError( errors[ 0 ] );
			return;
		}

		setBusy( true );

		fetch( CFG.rest, {
			method:  'POST',
			headers: {
				'Content-Type': 'application/json',
				'X-WP-Nonce':   CFG.restNonce,
			},
			body: JSON.stringify( buildPayload() ),
		} )
			.then( function ( res ) {
				return res.json().then( function ( data ) {
					return { ok: res.ok, status: res.status, data: data };
				} );
			} )
			.then( function ( r ) {
				if ( ! r.ok ) {
					setBusy( false );
					showError( r.data.message || CFG.i18n.serverError );
					return;
				}
				if ( r.data.redirect ) {
					window.location.href = r.data.redirect;
					return;
				}
				// Direct registration success.
				form.style.display = 'none';
				showSuccess( r.data.message || '' );
			} )
			.catch( function () {
				setBusy( false );
				showError( CFG.i18n.serverError );
			} );
	}

	function buildPayload() {
		var payload = { nonce: CFG.nonce, cancel_url: CFG.cancelUrl || '' };
		// Collect every named input inside the form — core fields + extension fields.
		form.querySelectorAll( '[name]' ).forEach( function ( el ) {
			if ( el.type === 'checkbox' || el.type === 'radio' ) {
				if ( el.checked ) {
					payload[ el.name ] = el.value;
				}
			} else if ( el.type === 'number' ) {
				payload[ el.name ] = el.value !== '' ? Number( el.value ) : null;
			} else {
				payload[ el.name ] = el.value.trim();
			}
		} );
		return payload;
	}

	function validateCore() {
		var errors = [];
		var i18n   = CFG.i18n || {};
		var name   = val( 'name' );
		var email  = val( 'email' );

		if ( name === '' ) {
			errors.push( fieldError( 'name', i18n.required ) );
		}
		if ( email === '' || ! /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test( email ) ) {
			errors.push( fieldError( 'email', i18n.emailInvalid ) );
		}

		return errors;
	}

	function fieldError( name, message ) {
		var el = form.querySelector( '[name="' + name + '"]' );
		if ( el ) {
			el.classList.add( 'pcio-ms-signup__input--error' );
		}
		return message;
	}

	function clearErrors() {
		form.querySelectorAll( '.pcio-ms-signup__input--error' ).forEach( function ( el ) {
			el.classList.remove( 'pcio-ms-signup__input--error' );
		} );
		errorBox.style.display   = 'none';
		errorBox.textContent     = '';
	}

	function showError( message ) {
		errorBox.textContent   = message;
		errorBox.style.display = '';
		errorBox.scrollIntoView( { behavior: 'smooth', block: 'nearest' } );
	}

	function showSuccess( message ) {
		if ( message && successBox ) {
			successBox.textContent   = message;
			successBox.style.display = '';
			successBox.scrollIntoView( { behavior: 'smooth', block: 'nearest' } );
		}
		setBusy( false );
	}

	function setBusy( busy ) {
		if ( submitBtn ) {
			submitBtn.disabled = busy;
		}
		if ( spinner ) {
			spinner.style.display = busy ? '' : 'none';
		}
	}

	function val( name ) {
		var el = form ? form.querySelector( '[name="' + name + '"]' ) : null;
		return el ? el.value.trim() : '';
	}

	if ( document.readyState === 'loading' ) {
		document.addEventListener( 'DOMContentLoaded', init );
	} else {
		init();
	}
}() );
