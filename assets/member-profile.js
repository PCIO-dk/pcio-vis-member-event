/* globals PCIO_ME_PROFILE */
( function () {
	'use strict';

	var CFG = window.PCIO_ME_PROFILE || {};
    const REST        = ( ( CFG.rest || '' ) + '/members' ).replace( /([^:]\/)\/+/g, '$1' );
    const NONCE       = CFG.nonce    || '';
    const MEMBER_ID   = CFG.memberId || 0;
    const T           = CFG.i18n     || {};

    var form, errorBox, successBox, submitBtn, spinner;

    document.addEventListener( 'DOMContentLoaded', () => {
    	form = document.getElementById( 'pcio-ms-profile-form' );
    	if ( ! form  ){ return; }
        init();
    } );

	function init() {

		errorBox   = document.querySelector( '.pcio-ms-profile__error' );
		successBox = document.querySelector( '.pcio-ms-profile__success' );
		submitBtn  = form.querySelector( '.pcio-ms-profile__submit' );
		spinner    = form.querySelector( '.pcio-ms-profile__spinner' );

        fillForm( form );

		form.addEventListener( 'submit', onSubmit );
	}

    function fillForm( form ) {
		var txtMemberNumber = document.getElementById( 'pcio-ms-member-number' );
        var txtName = document.getElementById( 'pcio-ms-name' );
		var txtEmail = document.getElementById( 'pcio-ms-email' );
		var txtPhone = document.getElementById( 'pcio-ms-phone' );
		var txtAddress = document.getElementById( 'pcio-ms-address' );

		const m = CFG.member || {};

        txtMemberNumber.value = m.member_number || '';
        txtName.value = m.name || '';
        txtEmail.value = m.email || '';
        txtPhone.value = m.phone || '';
        txtAddress.value = m.address || '';
    }
    
  
	function onSubmit( e ) {
		e.preventDefault();
		clearErrors();

		// Core validation.
		var errors = validateCore();

		// Let extensions add their own validation errors.
		if ( ! errors.length ) {
			var extEvent = new CustomEvent( 'pcioProfileValidate', {
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

        fetch( `${ REST }/${ MEMBER_ID }`, {
            method:  'PUT',
            headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': NONCE },
            body:    JSON.stringify( buildPayload() ),
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
  		 	    showSuccess( T.toastSaved );
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
			el.classList.add( 'pcio-ms-profile__input--error' );
		}
		return message;
	}

	function clearErrors() {
		form.querySelectorAll( '.pcio-ms-profile__input--error' ).forEach( function ( el ) {
			el.classList.remove( 'pcio-ms-profile__input--error' );
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
		if ( successBox ) {
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

}() );
