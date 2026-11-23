/**
 * SmokyForms calculations: the formula engine (same grammar and results as includes/Formula.php)
 * and the live results of forms with calculation fields. No eval, no dependencies.
 */
( function () {
	'use strict';

	var TWO = [ '<=', '>=', '==', '!=', '&&', '||' ];

	function tokenize( s ) {
		var out = [], i = 0, n = s.length, c, m;
		while ( i < n ) {
			c = s.charAt( i );
			if ( c === ' ' ) {
				i++;
				continue;
			}
			if ( /[0-9]/.test( c ) || ( c === '.' && /[0-9]/.test( s.charAt( i + 1 ) ) ) ) {
				m = /^[0-9]*\.?[0-9]+/.exec( s.slice( i ) );
				out.push( [ 'num', m[ 0 ] ] );
				i += m[ 0 ].length;
				continue;
			}
			if ( c === '{' ) {
				m = /^\{([a-z0-9_]+(?:\.[a-z0-9_]+)?)\}/.exec( s.slice( i ) );
				if ( ! m ) {
					throw new Error( 'Bad field reference' );
				}
				out.push( [ 'ref', m[ 1 ] ] );
				i += m[ 0 ].length;
				continue;
			}
			if ( /[a-zA-Z]/.test( c ) ) {
				m = /^[a-zA-Z_][a-zA-Z0-9_]*/.exec( s.slice( i ) );
				out.push( [ 'name', m[ 0 ].toLowerCase() ] );
				i += m[ 0 ].length;
				continue;
			}
			if ( TWO.indexOf( s.substr( i, 2 ) ) !== -1 ) {
				out.push( [ 'op', s.substr( i, 2 ) ] );
				i += 2;
				continue;
			}
			if ( '+-*/%^()<>!,'.indexOf( c ) !== -1 ) {
				out.push( [ 'op', c ] );
				i++;
				continue;
			}
			throw new Error( 'Unknown character' );
		}
		return out;
	}

	function Parser( tokens, ctx ) {
		this.t = tokens;
		this.p = 0;
		this.ctx = ctx;
	}

	Parser.prototype.is = function ( op ) {
		var t = this.t[ this.p ];
		return !! t && t[ 0 ] === 'op' && t[ 1 ] === op;
	};
	Parser.prototype.expect = function ( op ) {
		if ( ! this.is( op ) ) {
			throw new Error( 'Expected ' + op );
		}
		this.p++;
	};
	Parser.prototype.expr = function () {
		var v = this.and(), r;
		while ( this.is( '||' ) ) {
			this.p++;
			r = this.and();
			v = ( v !== 0 || r !== 0 ) ? 1 : 0;
		}
		return v;
	};
	Parser.prototype.and = function () {
		var v = this.cmp(), r;
		while ( this.is( '&&' ) ) {
			this.p++;
			r = this.cmp();
			v = ( v !== 0 && r !== 0 ) ? 1 : 0;
		}
		return v;
	};
	Parser.prototype.cmp = function () {
		var v = this.add(), ops = [ '<=', '>=', '==', '!=', '<', '>' ], i, r;
		for ( i = 0; i < ops.length; i++ ) {
			if ( this.is( ops[ i ] ) ) {
				this.p++;
				r = this.add();
				switch ( ops[ i ] ) {
					case '<=': return v <= r ? 1 : 0;
					case '>=': return v >= r ? 1 : 0;
					case '==': return Math.abs( v - r ) < 1e-9 ? 1 : 0;
					case '!=': return Math.abs( v - r ) < 1e-9 ? 0 : 1;
					case '<': return v < r ? 1 : 0;
					default: return v > r ? 1 : 0;
				}
			}
		}
		return v;
	};
	Parser.prototype.add = function () {
		var v = this.mul(), op, r;
		while ( this.is( '+' ) || this.is( '-' ) ) {
			op = this.t[ this.p++ ][ 1 ];
			r = this.mul();
			v = op === '+' ? v + r : v - r;
		}
		return v;
	};
	Parser.prototype.mul = function () {
		var v = this.unary(), op, r;
		while ( this.is( '*' ) || this.is( '/' ) || this.is( '%' ) ) {
			op = this.t[ this.p++ ][ 1 ];
			r = this.unary();
			if ( op === '*' ) {
				v = v * r;
			} else if ( r === 0 ) {
				v = 0;
			} else {
				v = op === '/' ? v / r : v % r;
			}
		}
		return v;
	};
	Parser.prototype.unary = function () {
		if ( this.is( '-' ) ) {
			this.p++;
			return -this.unary();
		}
		if ( this.is( '+' ) ) {
			this.p++;
			return this.unary();
		}
		if ( this.is( '!' ) ) {
			this.p++;
			return this.unary() === 0 ? 1 : 0;
		}
		return this.power();
	};
	Parser.prototype.power = function () {
		var v = this.primary();
		if ( this.is( '^' ) ) {
			this.p++;
			v = Math.pow( v, this.unary() );
		}
		return v;
	};
	Parser.prototype.primary = function () {
		var t = this.t[ this.p ], v;
		if ( ! t ) {
			throw new Error( 'Unexpected end' );
		}
		if ( t[ 0 ] === 'num' ) {
			this.p++;
			return parseFloat( t[ 1 ] );
		}
		if ( t[ 0 ] === 'ref' ) {
			this.p++;
			v = Number( this.ctx.num( t[ 1 ] ) );
			return isFinite( v ) ? v : 0;
		}
		if ( t[ 0 ] === 'op' && t[ 1 ] === '(' ) {
			this.p++;
			v = this.expr();
			this.expect( ')' );
			return v;
		}
		if ( t[ 0 ] === 'name' ) {
			this.p++;
			return this.call( t[ 1 ] );
		}
		throw new Error( 'Unexpected token' );
	};
	Parser.prototype.call = function ( name ) {
		var args = [], n, table, keys, ref;
		this.expect( '(' );
		if ( name === 'lookup' ) {
			table = this.t[ this.p ];
			if ( ! table || table[ 0 ] !== 'name' ) {
				throw new Error( 'Table expected' );
			}
			this.p++;
			keys = [];
			while ( this.is( ',' ) ) {
				this.p++;
				ref = this.t[ this.p ];
				if ( ! ref || ref[ 0 ] !== 'ref' ) {
					throw new Error( 'Field expected' );
				}
				this.p++;
				keys.push( String( this.ctx.text( ref[ 1 ] ) ) );
			}
			this.expect( ')' );
			if ( keys.length < 1 || keys.length > 2 ) {
				throw new Error( 'lookup needs one or two fields' );
			}
			return lookup( this.ctx.tables, table[ 1 ], keys );
		}
		if ( ! this.is( ')' ) ) {
			args.push( this.expr() );
			while ( this.is( ',' ) ) {
				this.p++;
				args.push( this.expr() );
			}
		}
		this.expect( ')' );
		n = args.length;
		switch ( name ) {
			case 'if': if ( n === 3 ) { return args[ 0 ] !== 0 ? args[ 1 ] : args[ 2 ]; } break;
			case 'round': if ( n >= 1 && n <= 2 ) { return roundTo( args[ 0 ], n > 1 ? Math.trunc( args[ 1 ] ) : 0 ); } break;
			case 'floor': if ( n === 1 ) { return Math.floor( args[ 0 ] ); } break;
			case 'ceil': if ( n === 1 ) { return Math.ceil( args[ 0 ] ); } break;
			case 'abs': if ( n === 1 ) { return Math.abs( args[ 0 ] ); } break;
			case 'sqrt': if ( n === 1 ) { return args[ 0 ] < 0 ? 0 : Math.sqrt( args[ 0 ] ); } break;
			case 'min': if ( n >= 1 ) { return Math.min.apply( null, args ); } break;
			case 'max': if ( n >= 1 ) { return Math.max.apply( null, args ); } break;
			case 'days': if ( n === 2 ) { return ( args[ 0 ] === 0 || args[ 1 ] === 0 ) ? 0 : args[ 1 ] - args[ 0 ]; } break;
		}
		throw new Error( 'Unknown function or wrong arguments: ' + name );
	};

	// PHP round(): half away from zero.
	function roundTo( v, d ) {
		var f = Math.pow( 10, d ), x = Math.abs( v ) * f;
		x = Math.round( x + 1e-9 );
		return ( v < 0 ? -x : x ) / f;
	}

	function lookup( tables, id, keys ) {
		var table = null, i, row, col = 0, cell;
		for ( i = 0; i < ( tables || [] ).length; i++ ) {
			if ( tables[ i ].id === id ) {
				table = tables[ i ];
			}
		}
		if ( ! table ) {
			return 0;
		}
		row = table.rows.indexOf( keys[ 0 ] );
		if ( row === -1 ) {
			return 0;
		}
		if ( keys.length > 1 && table.cols.length ) {
			col = table.cols.indexOf( keys[ 1 ] );
			if ( col === -1 ) {
				return 0;
			}
		}
		cell = table.cells[ row ] ? table.cells[ row ][ col ] : 0;
		cell = parseFloat( cell );
		return isFinite( cell ) ? cell : 0;
	}

	function evaluate( formula, num, text, tables ) {
		var parser, v;
		try {
			parser = new Parser( tokenize( String( formula || '' ) ), {
				num: num,
				text: text || function () { return ''; },
				tables: tables || [],
			} );
			if ( ! parser.t.length ) {
				return 0;
			}
			v = parser.expr();
			if ( parser.p !== parser.t.length ) {
				throw new Error( 'Unexpected token' );
			}
		} catch ( e ) {
			return 0;
		}
		return isFinite( v ) ? v : 0;
	}

	/**
	 * Checks a formula. Returns '' when valid, otherwise the parser's (English) reason.
	 */
	function check( formula ) {
		var parser;
		try {
			parser = new Parser( tokenize( String( formula || '' ) ), {
				num: function () { return 1; },
				text: function () { return ''; },
				tables: [],
			} );
			parser.expr();
			if ( parser.p !== parser.t.length ) {
				throw new Error( 'Unexpected token' );
			}
			return '';
		} catch ( e ) {
			return e.message || 'Invalid';
		}
	}

	function format( number, decimals, sep ) {
		var fixed = roundTo( number, decimals ).toFixed( decimals ),
			parts = fixed.split( '.' ),
			neg = parts[ 0 ].charAt( 0 ) === '-',
			int = neg ? parts[ 0 ].slice( 1 ) : parts[ 0 ];
		int = int.replace( /\B(?=(\d{3})+(?!\d))/g, sep.thousands );
		return ( neg ? '-' : '' ) + int + ( decimals > 0 ? sep.decimal + parts[ 1 ] : '' );
	}

	// Days since 1 January 1970 (UTC) of a YYYY-MM-DD date, as in Fields::numeric().
	function dayNumber( value ) {
		var m = /^(\d{4})-(\d{2})-(\d{2})$/.exec( value || '' );
		return m ? Math.floor( Date.UTC( +m[ 1 ], +m[ 2 ] - 1, +m[ 3 ] ) / 86400000 ) : 0;
	}

	function numberText( n, decimals ) {
		var s = roundTo( n, decimals ).toFixed( decimals );
		return s.indexOf( '.' ) !== -1 ? s.replace( /0+$/, '' ).replace( /\.$/, '' ) : s;
	}

	/* Live results in a form. */

	function setup( form ) {
		var config, values;
		try {
			config = JSON.parse( form.getAttribute( 'data-calc' ) );
		} catch ( e ) {
			return;
		}

		function raw( id ) {
			var items = form.querySelectorAll( '[name="smokyforms[' + id + ']"], [name="smokyforms[' + id + '][]"]' ),
				out = [], i, el;
			for ( i = 0; i < items.length; i++ ) {
				el = items[ i ];
				// Fields hidden by conditional logic are disabled and count as empty.
				if ( el.disabled ) {
					continue;
				}
				if ( el.type === 'radio' || el.type === 'checkbox' ) {
					if ( el.checked ) {
						out.push( el.value );
					}
				} else {
					out.push( el.value );
				}
			}
			return out;
		}

		// Filled rows of a repeating group: name => value of each field of the row.
		function rows( id ) {
			var box = form.querySelector( '.smokyforms-field[data-field="' + id + '"]' ), list = [], items, i, j, row, el, filled, key, m;
			if ( ! box || box.closest( '[data-logic][hidden]' ) ) {
				return list;
			}
			items = box.querySelectorAll( '.smokyforms-row' );
			for ( i = 0; i < items.length; i++ ) {
				row = {};
				filled = false;
				el = items[ i ].querySelectorAll( 'input, select, textarea' );
				for ( j = 0; j < el.length; j++ ) {
					m = /\]\[[^\]]*\]\[([a-z0-9_]+)\]$/.exec( el[ j ].name );
					if ( ! m ) {
						continue;
					}
					key = m[ 1 ];
					row[ key ] = el[ j ].value;
					if ( String( el[ j ].value ).trim() !== '' ) {
						filled = true;
					}
				}
				if ( filled ) {
					list.push( row );
				}
			}
			return list;
		}

		// {group.field}: the sum of that field over the rows.
		function group( ref ) {
			var parts = ref.split( '.' ), f = config.fields[ parts[ 0 ] ], sub, list, sum = 0, i, v;
			if ( ! f || f.type !== 'repeater' || ! parts[ 1 ] || ! f.subs || ! f.subs[ parts[ 1 ] ] ) {
				return 0;
			}
			sub = f.subs[ parts[ 1 ] ];
			list = rows( parts[ 0 ] );
			for ( i = 0; i < list.length; i++ ) {
				v = list[ i ][ parts[ 1 ] ];
				if ( sub.options ) {
					sum += Object.prototype.hasOwnProperty.call( sub.options, v ) ? Number( sub.options[ v ] ) || 0 : 0;
				} else if ( sub.type === 'date' ) {
					sum += dayNumber( v );
				} else {
					v = parseFloat( String( v || '' ).replace( ',', '.' ) );
					sum += isFinite( v ) ? v : 0;
				}
			}
			return sum;
		}

		function run() {
			var done = {}, stack = {}, i, c, out, hidden, num, text;
			text = function ( id ) {
				var parts = id.split( '.' ), v, list;
				if ( parts[ 1 ] ) {
					list = rows( parts[ 0 ] );
					return list.length && list[ 0 ][ parts[ 1 ] ] !== undefined ? String( list[ 0 ][ parts[ 1 ] ] ) : '';
				}
				v = raw( id );
				return v.length ? v[ 0 ] : '';
			};
			num = function ( id ) {
				var f = config.fields[ id ], v, sum, j, calc;
				if ( ! f ) {
					return group( id );
				}
				if ( f.type === 'repeater' ) {
					return rows( id ).length;
				}
				if ( f.type === 'file' ) {
					v = form.querySelector( '[name="smokyforms_files[' + id + '][]"]' );
					return v && ! v.disabled && v.files ? v.files.length : 0;
				}
				if ( f.type === 'calculation' ) {
					if ( Object.prototype.hasOwnProperty.call( done, id ) ) {
						return done[ id ];
					}
					if ( stack[ id ] ) {
						return 0;
					}
					stack[ id ] = true;
					calc = null;
					for ( j = 0; j < config.calcs.length; j++ ) {
						if ( config.calcs[ j ].id === id ) {
							calc = config.calcs[ j ];
						}
					}
					done[ id ] = calc ? evaluate( calc.formula, num, text, config.tables ) : 0;
					delete stack[ id ];
					return done[ id ];
				}
				v = raw( id );
				if ( f.options ) {
					sum = 0;
					for ( j = 0; j < v.length; j++ ) {
						if ( Object.prototype.hasOwnProperty.call( f.options, v[ j ] ) ) {
							sum += Number( f.options[ v[ j ] ] ) || 0;
						}
					}
					return sum;
				}
				if ( f.type === 'date' ) {
					return dayNumber( v[ 0 ] );
				}
				v = parseFloat( String( v[ 0 ] || '' ).replace( ',', '.' ) );
				return isFinite( v ) ? v : 0;
			};
			for ( i = 0; i < config.calcs.length; i++ ) {
				c = config.calcs[ i ];
				values = num( c.id );
				out = form.querySelector( '[data-result="' + c.id + '"]' );
				hidden = form.querySelector( 'input[type="hidden"][name="smokyforms[' + c.id + ']"]' );
				if ( out ) {
					out.textContent = ( c.prefix + format( values, c.decimals, config.number ) + c.suffix ).trim();
				}
				if ( hidden ) {
					hidden.value = numberText( values, c.decimals );
				}
			}
		}

		form.smokyformsCalc = run;
		form.addEventListener( 'input', run );
		form.addEventListener( 'change', run );
		form.addEventListener( 'reset', function () {
			setTimeout( run, 0 );
		} );
		run();
	}

	window.SmokyFormsCalc = {
		evaluate: evaluate,
		check: check,
		format: format,
		dayNumber: dayNumber,
	};

	function init() {
		var forms = document.querySelectorAll( 'form.smokyforms[data-calc]' ), i;
		for ( i = 0; i < forms.length; i++ ) {
			setup( forms[ i ] );
		}
	}
	if ( document.readyState === 'loading' ) {
		document.addEventListener( 'DOMContentLoaded', init );
	} else {
		init();
	}
}() );
