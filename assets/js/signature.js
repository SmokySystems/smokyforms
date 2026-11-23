/**
 * SmokyForms signature pad: draw with a mouse, finger or pen. The signature is sent as a
 * PNG image (black on white, whatever the colours of the theme).
 */
( function () {
	'use strict';

	function setup( box ) {
		var canvas = box.querySelector( 'canvas' ),
			field = box.closest( '.smokyforms-field' ),
			input = field.querySelector( 'input[data-signature]' ),
			clear = box.querySelector( '.smokyforms-signature-clear' ),
			form = box.closest( 'form' ),
			ctx = canvas.getContext( '2d' ),
			strokes = [], current = null, width = 0, height = 0;

		function size() {
			var ratio = window.devicePixelRatio || 1, w = canvas.clientWidth, h = canvas.clientHeight;
			if ( ! w || ( w === width && h === height ) ) {
				return;
			}
			width = w;
			height = h;
			canvas.width = Math.round( w * ratio );
			canvas.height = Math.round( h * ratio );
			ctx.setTransform( ratio, 0, 0, ratio, 0, 0 );
			draw( ctx, w, h, getComputedStyle( canvas ).color );
		}

		function draw( c, w, h, color, background ) {
			var i, j, s;
			c.clearRect( 0, 0, w, h );
			if ( background ) {
				c.fillStyle = background;
				c.fillRect( 0, 0, w, h );
			}
			c.lineWidth = Math.max( 1.5, w / 300 );
			c.lineCap = 'round';
			c.lineJoin = 'round';
			c.strokeStyle = color;
			c.fillStyle = color;
			for ( i = 0; i < strokes.length; i++ ) {
				s = strokes[ i ];
				c.beginPath();
				c.moveTo( s[ 0 ][ 0 ] * w, s[ 0 ][ 1 ] * h );
				if ( s.length === 1 ) {
					c.arc( s[ 0 ][ 0 ] * w, s[ 0 ][ 1 ] * h, c.lineWidth / 2, 0, Math.PI * 2 );
					c.fill();
					continue;
				}
				for ( j = 1; j < s.length; j++ ) {
					c.lineTo( s[ j ][ 0 ] * w, s[ j ][ 1 ] * h );
				}
				c.stroke();
			}
		}

		function save() {
			var out, c, w = 600, h;
			if ( ! strokes.length ) {
				input.value = '';
				return;
			}
			h = Math.round( w * ( height / width ) ) || 200;
			out = document.createElement( 'canvas' );
			out.width = w;
			out.height = h;
			c = out.getContext( '2d' );
			draw( c, w, h, '#000000', '#ffffff' );
			input.value = out.toDataURL( 'image/png' );
		}

		function point( e ) {
			var r = canvas.getBoundingClientRect();
			return [ Math.min( 1, Math.max( 0, ( e.clientX - r.left ) / r.width ) ), Math.min( 1, Math.max( 0, ( e.clientY - r.top ) / r.height ) ) ];
		}

		canvas.addEventListener( 'pointerdown', function ( e ) {
			if ( e.button > 0 ) {
				return;
			}
			size();
			e.preventDefault();
			canvas.setPointerCapture( e.pointerId );
			current = [ point( e ) ];
			strokes.push( current );
			draw( ctx, width, height, getComputedStyle( canvas ).color );
		} );
		canvas.addEventListener( 'pointermove', function ( e ) {
			if ( ! current ) {
				return;
			}
			current.push( point( e ) );
			draw( ctx, width, height, getComputedStyle( canvas ).color );
		} );
		function end() {
			if ( ! current ) {
				return;
			}
			current = null;
			save();
			input.dispatchEvent( new Event( 'change', { bubbles: true } ) );
		}
		canvas.addEventListener( 'pointerup', end );
		canvas.addEventListener( 'pointercancel', end );

		function reset() {
			strokes = [];
			current = null;
			input.value = '';
			draw( ctx, width, height, getComputedStyle( canvas ).color );
		}
		clear.addEventListener( 'click', function () {
			reset();
			input.dispatchEvent( new Event( 'change', { bubbles: true } ) );
		} );
		if ( form ) {
			form.addEventListener( 'reset', reset );
		}

		// The pad may start hidden (in a later step or behind a condition).
		if ( window.ResizeObserver ) {
			new window.ResizeObserver( size ).observe( canvas );
		} else {
			window.addEventListener( 'resize', size );
		}
		size();
	}

	function init() {
		var boxes = document.querySelectorAll( 'form.smokyforms .smokyforms-signature' ), i;
		for ( i = 0; i < boxes.length; i++ ) {
			setup( boxes[ i ] );
		}
	}
	if ( document.readyState === 'loading' ) {
		document.addEventListener( 'DOMContentLoaded', init );
	} else {
		init();
	}
}() );
