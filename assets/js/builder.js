/**
 * SmokyForms form builder. Runs on WordPress' own React (wp.element) and components; no build step.
 */
( function ( wp, data ) {
	'use strict';

	var el = wp.element.createElement,
		Fragment = wp.element.Fragment,
		useState = wp.element.useState,
		useEffect = wp.element.useEffect,
		useRef = wp.element.useRef,
		__ = wp.i18n.__,
		sprintf = wp.i18n.sprintf,
		c = wp.components,
		Calc = window.SmokyFormsCalc;

	var TYPES = {};
	data.types.forEach( function ( t ) {
		TYPES[ t.type ] = t;
	} );

	var GROUPS = [
		[ 'basic', __( 'Basic fields', 'smokyforms' ) ],
		[ 'choice', __( 'Choices', 'smokyforms' ) ],
		[ 'special', __( 'Special fields', 'smokyforms' ) ],
		[ 'layout', __( 'Layout', 'smokyforms' ) ],
	];

	var FUNCTIONS = [
		{ name: 'if', insert: 'if(', label: __( 'if … then … else', 'smokyforms' ), help: __( 'if(condition, value when true, value when false)', 'smokyforms' ) },
		{ name: 'round', insert: 'round(', label: __( 'Round', 'smokyforms' ), help: __( 'round(value, decimals)', 'smokyforms' ) },
		{ name: 'min', insert: 'min(', label: __( 'Smallest', 'smokyforms' ), help: __( 'min(a, b, …)', 'smokyforms' ) },
		{ name: 'max', insert: 'max(', label: __( 'Largest', 'smokyforms' ), help: __( 'max(a, b, …)', 'smokyforms' ) },
		{ name: 'days', insert: 'days(', label: __( 'Days between dates', 'smokyforms' ), help: __( 'days(start date, end date)', 'smokyforms' ) },
		{ name: 'floor', insert: 'floor(', label: __( 'Round down', 'smokyforms' ), help: 'floor(x)' },
		{ name: 'ceil', insert: 'ceil(', label: __( 'Round up', 'smokyforms' ), help: 'ceil(x)' },
		{ name: 'abs', insert: 'abs(', label: __( 'Without sign', 'smokyforms' ), help: 'abs(x)' },
		{ name: 'sqrt', insert: 'sqrt(', label: __( 'Square root', 'smokyforms' ), help: 'sqrt(x)' },
	];

	/* Helpers */

	function clone( o ) {
		return JSON.parse( JSON.stringify( o ) );
	}

	function uid( fields, base ) {
		var id = String( base || 'field' ).toLowerCase().replace( /[^a-z0-9_]/g, '' ) || 'field', n = 1, taken = {};
		fields.forEach( function ( f ) {
			taken[ f.id ] = true;
		} );
		if ( ! /^[a-z]/.test( id ) ) {
			id = 'f' + id;
		}
		if ( ! taken[ id ] ) {
			return id;
		}
		while ( taken[ id + n ] ) {
			n++;
		}
		return id + n;
	}

	function slug( text ) {
		return String( text || '' ).toLowerCase().replace( /ı/g, 'i' ).replace( /ß/g, 'ss' ).normalize( 'NFD' ).replace( /[\u0300-\u036f]/g, '' )
			.replace( /[^a-z0-9]+/g, '_' ).replace( /^_+|_+$/g, '' ).slice( 0, 30 );
	}

	function newField( type, fields ) {
		var t = TYPES[ type ], f = {
			id: uid( fields, type === 'calculation' ? 'total' : type ),
			type: type,
			label: t ? t.label : type,
			required: false,
			help: '',
			placeholder: '',
			default: '',
			width: 'full',
		};
		if ( type === 'select' || type === 'radio' || type === 'checkbox' ) {
			f.options = [
				{ label: __( 'First choice', 'smokyforms' ), calc: 0, selected: false },
				{ label: __( 'Second choice', 'smokyforms' ), calc: 0, selected: false },
				{ label: __( 'Third choice', 'smokyforms' ), calc: 0, selected: false },
			];
		}
		if ( type === 'number' ) {
			f.min = '';
			f.max = '';
			f.step = '';
		}
		if ( type === 'calculation' ) {
			f.formula = '';
			f.decimals = 2;
			f.prefix = '';
			f.suffix = '';
			f.visible = true;
		}
		if ( type === 'consent' ) {
			f.label = __( 'Consent', 'smokyforms' );
			f.text = __( 'I agree to the storage and handling of my data by this website.', 'smokyforms' );
			f.required = true;
		}
		if ( type === 'html' ) {
			f.label = __( 'Text block', 'smokyforms' );
			f.content = '';
		}
		if ( type === 'heading' ) {
			f.label = __( 'Section heading', 'smokyforms' );
		}
		if ( type === 'rating' ) {
			f.max = 5;
		}
		if ( type === 'file' ) {
			f.extensions = data.upload.extensions;
			f.max_size = Math.min( 5, data.upload.maxSize );
			f.max_files = 1;
			f.attach = false;
		}
		if ( type === 'signature' ) {
			f.attach = false;
		}
		if ( type === 'repeater' ) {
			f.label = __( 'Repeating group', 'smokyforms' );
			f.min_rows = 1;
			f.max_rows = 10;
			f.add_label = '';
			f.subfields = [ newSub( 'text', [] ) ];
		}
		if ( type === 'page' ) {
			f.label = '';
			f.prev_label = '';
			f.next_label = '';
		}
		f.logic = null;
		f._autoId = true;
		return f;
	}

	var SUB_TYPES = [ 'text', 'textarea', 'email', 'phone', 'url', 'number', 'select', 'date' ];

	function newSub( type, subs ) {
		var s = newField( type, subs );
		delete s.logic;
		return s;
	}

	// Values a formula can use: numeric fields, and for a repeating group the number of rows
	// and the total of each number or dropdown field of its rows ({group.field}).
	function refEntries( fields, except ) {
		var out = [];
		fields.forEach( function ( x ) {
			if ( x.id === except || ! TYPES[ x.type ] || ! TYPES[ x.type ].numeric ) {
				return;
			}
			if ( x.type === 'repeater' ) {
				out.push( { ref: x.id, label: sprintf( __( '%s: number of rows', 'smokyforms' ), x.label || x.id ), field: x } );
				( x.subfields || [] ).forEach( function ( sub ) {
					if ( sub.type === 'number' || sub.type === 'select' ) {
						out.push( { ref: x.id + '.' + sub.id, label: sprintf( __( '%1$s: total of %2$s', 'smokyforms' ), x.label || x.id, sub.label || sub.id ), field: sub, group: x } );
					}
				} );
				return;
			}
			if ( x.type === 'file' ) {
				out.push( { ref: x.id, label: sprintf( __( '%s: number of files', 'smokyforms' ), x.label || x.id ), field: x } );
				return;
			}
			out.push( { ref: x.id, label: x.label || x.id, field: x } );
		} );
		return out;
	}

	function refLabel( fields, ref ) {
		var hit = refEntries( fields, null ).filter( function ( e ) {
			return e.ref === ref;
		} )[ 0 ];
		return hit ? hit.label : null;
	}

	function tokens( formula ) {
		// Display tokens; mirrors the engine's tokenizer, unknown characters become single tokens.
		var s = String( formula || '' ), out = [], i = 0, m, two;
		while ( i < s.length ) {
			if ( s.charAt( i ) === ' ' ) {
				i++;
				continue;
			}
			m = /^[0-9]*\.?[0-9]+/.exec( s.slice( i ) );
			if ( m ) {
				out.push( { k: 'num', t: m[ 0 ] } );
				i += m[ 0 ].length;
				continue;
			}
			m = /^\{([a-z0-9_]+(?:\.[a-z0-9_]+)?)\}/.exec( s.slice( i ) );
			if ( m ) {
				out.push( { k: 'ref', t: m[ 1 ] } );
				i += m[ 0 ].length;
				continue;
			}
			m = /^[a-zA-Z_][a-zA-Z0-9_]*/.exec( s.slice( i ) );
			if ( m ) {
				out.push( { k: 'name', t: m[ 0 ] } );
				i += m[ 0 ].length;
				continue;
			}
			two = s.substr( i, 2 );
			if ( [ '<=', '>=', '==', '!=', '&&', '||' ].indexOf( two ) !== -1 ) {
				out.push( { k: 'op', t: two } );
				i += 2;
				continue;
			}
			out.push( { k: 'op', t: s.charAt( i ) } );
			i++;
		}
		return out;
	}

	function joinTokens( list ) {
		return list.map( function ( t ) {
			return t.k === 'ref' ? '{' + t.t + '}' : t.t;
		} ).join( ' ' ).replace( /\( /g, '(' ).replace( / \)/g, ')' ).replace( / ,/g, ',' );
	}

	var OP_LABELS = { '*': '×', '/': '÷', '-': '−', '<=': '≤', '>=': '≥', '==': '=', '!=': '≠', '&&': __( 'and', 'smokyforms' ), '||': __( 'or', 'smokyforms' ) };

	/* Field palette */

	function Palette( props ) {
		return el(
			'div',
			{ className: 'smokyforms-palette' },
			GROUPS.map( function ( g ) {
				var items = data.types.filter( function ( t ) {
					return t.group === g[ 0 ];
				} );
				if ( ! items.length ) {
					return null;
				}
				return el(
					'div',
					{ key: g[ 0 ], className: 'smokyforms-palette-group' },
					el( 'h3', null, g[ 1 ] ),
					el(
						'div',
						{ className: 'smokyforms-palette-items' },
						items.map( function ( t ) {
							return el(
								c.Button,
								{
									key: t.type,
									variant: 'secondary',
									className: 'smokyforms-palette-item' + ( t.type === 'calculation' ? ' is-calc' : '' ),
									draggable: true,
									onDragStart: function ( e ) {
										e.dataTransfer.effectAllowed = 'copy';
										e.dataTransfer.setData( 'text/plain', 'smokyforms-new:' + t.type );
									},
									onClick: function () {
										props.onAdd( t.type );
									},
								},
								t.label
							);
						} )
					)
				);
			} )
		);
	}

	/* Canvas */

	function FieldPreview( props ) {
		var f = props.field, t = TYPES[ f.type ] || { label: f.type }, body;
		switch ( f.type ) {
			case 'heading':
				body = el( 'h3', { className: 'smokyforms-preview-heading' }, f.label );
				break;
			case 'html':
				body = el( 'div', { className: 'smokyforms-preview-html' }, f.content ? f.content.replace( /<[^>]+>/g, ' ' ).slice( 0, 160 ) : __( 'Empty text block', 'smokyforms' ) );
				break;
			case 'calculation':
				body = el(
					Fragment,
					null,
					el( 'span', { className: 'smokyforms-preview-label' }, f.label ),
					el( 'span', { className: 'smokyforms-preview-formula' }, f.formula ? tokens( f.formula ).map( function ( t ) {
						var x;
						if ( t.k === 'ref' ) {
							x = ( props.fields || [] ).filter( function ( y ) {
								return y.id === t.t;
							} )[ 0 ];
							return refLabel( props.fields || [], t.t ) || ( x ? x.label || x.id : '?' );
						}
						return t.k === 'op' && OP_LABELS[ t.t ] ? OP_LABELS[ t.t ] : t.t;
					} ).join( ' ' ).replace( /\( /g, '(' ).replace( / \)/g, ')' ).replace( / ,/g, ',' ) : __( 'No formula yet', 'smokyforms' ) )
				);
				break;
			case 'page':
				return el(
					'div',
					{ className: 'smokyforms-preview smokyforms-preview-page' },
					el( 'span', { className: 'smokyforms-preview-type' }, t.label ),
					el( 'span', { className: 'smokyforms-preview-label' }, f.label || __( 'Next page', 'smokyforms' ) )
				);
			case 'rating':
				body = el(
					Fragment,
					null,
					el( 'span', { className: 'smokyforms-preview-label' }, f.label, f.required ? el( 'span', { className: 'smokyforms-preview-required' }, ' *' ) : null ),
					el( 'span', { className: 'smokyforms-preview-stars', 'aria-hidden': true }, new Array( ( f.max || 5 ) + 1 ).join( '★' ) )
				);
				break;
			case 'repeater':
				body = el(
					Fragment,
					null,
					el( 'span', { className: 'smokyforms-preview-label' }, f.label, f.required ? el( 'span', { className: 'smokyforms-preview-required' }, ' *' ) : null ),
					el( 'span', { className: 'smokyforms-preview-options' }, ( f.subfields || [] ).map( function ( x ) {
						return x.label || x.id;
					} ).join( ' · ' ) || __( 'No fields yet', 'smokyforms' ) )
				);
				break;
			case 'select':
			case 'radio':
			case 'checkbox':
				body = el(
					Fragment,
					null,
					el( 'span', { className: 'smokyforms-preview-label' }, f.label, f.required ? el( 'span', { className: 'smokyforms-preview-required' }, ' *' ) : null ),
					el( 'span', { className: 'smokyforms-preview-options' }, ( f.options || [] ).map( function ( o ) {
						return o.label;
					} ).join( ' · ' ) )
				);
				break;
			default:
				body = el(
					Fragment,
					null,
					el( 'span', { className: 'smokyforms-preview-label' }, f.label || el( 'em', null, __( 'No label', 'smokyforms' ) ), f.required ? el( 'span', { className: 'smokyforms-preview-required' }, ' *' ) : null ),
					el( 'span', { className: 'smokyforms-preview-input' }, f.placeholder || ' ' )
				);
		}
		return el(
			'div',
			{ className: 'smokyforms-preview' },
			el( 'span', { className: 'smokyforms-preview-type' }, t.label, f.logic ? el( 'span', { className: 'smokyforms-preview-logic' }, __( 'Conditional logic', 'smokyforms' ) ) : null ),
			body
		);
	}

	function Canvas( props ) {
		var drag = useRef( null );
		function dropEnd( e ) {
			var data;
			if ( e.target !== e.currentTarget ) {
				return;
			}
			e.preventDefault();
			data = e.dataTransfer.getData( 'text/plain' ) || '';
			if ( data.indexOf( 'smokyforms-new:' ) === 0 ) {
				props.onInsert( data.slice( 15 ), props.fields.length );
			}
		}
		if ( ! props.fields.length ) {
			return el(
				'div',
				{ className: 'smokyforms-canvas is-empty', onDragOver: function ( e ) {
					e.preventDefault();
				}, onDrop: dropEnd },
				el( 'p', null, __( 'Click or drag fields from the list on the left.', 'smokyforms' ) )
			);
		}
		return el(
			'div',
			{ className: 'smokyforms-canvas', onDragOver: function ( e ) {
				e.preventDefault();
			}, onDrop: dropEnd },
			props.fields.map( function ( f, i ) {
				var selected = props.selected === f.id;
				return el(
					'div',
					{
						key: f.id,
						className: 'smokyforms-card' + ( selected ? ' is-selected' : '' ) + ( f.width === 'half' ? ' is-half' : '' ),
						draggable: true,
						onDragStart: function ( e ) {
							drag.current = i;
							e.dataTransfer.effectAllowed = 'move';
							try {
								e.dataTransfer.setData( 'text/plain', f.id );
							} catch ( err ) {}
						},
						onDragOver: function ( e ) {
							e.preventDefault();
						},
						onDrop: function ( e ) {
							e.preventDefault();
							var data = e.dataTransfer.getData( 'text/plain' ) || '';
							if ( data.indexOf( 'smokyforms-new:' ) === 0 ) {
								props.onInsert( data.slice( 15 ), i );
							} else if ( drag.current !== null && drag.current !== i ) {
								props.onMove( drag.current, i );
							}
							drag.current = null;
						},
						onClick: function () {
							props.onSelect( f.id );
						},
						onKeyDown: function ( e ) {
							if ( e.key === 'Enter' && e.target === e.currentTarget ) {
								props.onSelect( f.id );
							}
						},
						tabIndex: 0,
						role: 'button',
						'aria-pressed': selected,
						'aria-label': sprintf( __( 'Field: %s', 'smokyforms' ), f.label || f.id ),
					},
					el( FieldPreview, { field: f, fields: props.fields } ),
					el(
						'div',
						{ className: 'smokyforms-card-actions', onClick: function ( e ) {
							e.stopPropagation();
						} },
						el( c.Button, { icon: 'arrow-up-alt2', label: __( 'Move up', 'smokyforms' ), size: 'small', disabled: i === 0, onClick: function () {
							props.onMove( i, i - 1 );
						} } ),
						el( c.Button, { icon: 'arrow-down-alt2', label: __( 'Move down', 'smokyforms' ), size: 'small', disabled: i === props.fields.length - 1, onClick: function () {
							props.onMove( i, i + 1 );
						} } ),
						el( c.Button, { icon: 'admin-page', label: __( 'Duplicate', 'smokyforms' ), size: 'small', onClick: function () {
							props.onDuplicate( i );
						} } ),
						el( c.Button, { icon: 'trash', label: __( 'Delete', 'smokyforms' ), size: 'small', isDestructive: true, onClick: function () {
							props.onDelete( i );
						} } )
					)
				);
			} )
		);
	}

	/* Options editor */

	function OptionsEditor( props ) {
		var f = props.field, opts = f.options || [];
		function set( list ) {
			props.onChange( { options: list } );
		}
		function update( i, patch ) {
			var list = clone( opts );
			Object.keys( patch ).forEach( function ( k ) {
				list[ i ][ k ] = patch[ k ];
			} );
			if ( patch.selected && f.type !== 'checkbox' ) {
				list.forEach( function ( o, j ) {
					if ( j !== i ) {
						o.selected = false;
					}
				} );
			}
			set( list );
		}
		return el(
			'div',
			{ className: 'smokyforms-options' },
			el(
				'div',
				{ className: 'smokyforms-options-head' },
				el( 'span', null, __( 'Choice', 'smokyforms' ) ),
				el( 'span', null, __( 'Value in calculations', 'smokyforms' ) ),
				el( 'span', null, __( 'Default', 'smokyforms' ) )
			),
			opts.map( function ( o, i ) {
				return el(
					'div',
					{ key: i, className: 'smokyforms-option' },
					el( 'input', { type: 'text', value: o.label, 'aria-label': __( 'Choice', 'smokyforms' ), onChange: function ( e ) {
						update( i, { label: e.target.value } );
					} } ),
					el( 'input', { type: 'number', step: 'any', value: o.calc, 'aria-label': __( 'Value in calculations', 'smokyforms' ), onChange: function ( e ) {
						update( i, { calc: e.target.value === '' ? 0 : parseFloat( e.target.value ) } );
					} } ),
					el( 'input', { type: f.type === 'checkbox' ? 'checkbox' : 'radio', checked: !! o.selected, 'aria-label': __( 'Default', 'smokyforms' ), onChange: function ( e ) {
						update( i, { selected: e.target.checked } );
					}, onClick: function ( e ) {
						if ( f.type !== 'checkbox' && o.selected ) {
							update( i, { selected: false } );
							e.preventDefault();
						}
					} } ),
					el( c.Button, { icon: 'no-alt', size: 'small', label: __( 'Remove choice', 'smokyforms' ), onClick: function () {
						var list = clone( opts );
						list.splice( i, 1 );
						set( list );
					} } )
				);
			} ),
			el( c.Button, { variant: 'secondary', size: 'compact', onClick: function () {
				set( opts.concat( [ { label: sprintf( __( 'Choice %d', 'smokyforms' ), opts.length + 1 ), calc: 0, selected: false } ] ) );
			} }, __( 'Add choice', 'smokyforms' ) ),
			el( 'p', { className: 'description' }, __( 'The value is what this choice counts as in a calculation, for example its price. For checkboxes the values of all ticked choices are added up.', 'smokyforms' ) )
		);
	}

	/* Formula editor */

	function FormulaEditor( props ) {
		var f = props.field,
			formula = f.formula || '',
			list = tokens( formula ),
			mode = useState( 'click' ),
			numState = useState( '' ),
			lookupState = useState( { table: '', row: '', col: '' } ),
			test = useState( {} ),
			others = refEntries( props.fields, f.id ),
			byId = {},
			choiceFields = props.fields.filter( function ( x ) {
				return x.type === 'select' || x.type === 'radio';
			} ),
			problem = formula ? Calc.check( formula ) : '',
			refs = [];

		props.fields.forEach( function ( x ) {
			byId[ x.id ] = x;
		} );
		list.forEach( function ( t ) {
			if ( t.k === 'ref' && refs.indexOf( t.t ) === -1 ) {
				refs.push( t.t );
			}
		} );

		function setFormula( value ) {
			props.onChange( { formula: value } );
		}
		function add( text ) {
			setFormula( joinTokens( list.concat( tokens( text ) ) ) );
		}
		function undo() {
			setFormula( joinTokens( list.slice( 0, -1 ) ) );
		}

		// Test values.
		var values = test[ 0 ], setValues = test[ 1 ];
		var preview = Calc.evaluate(
			formula,
			function num( id ) {
				var x = byId[ id ], v = values[ id ], sum, o;
				if ( ! x || x.type === 'repeater' || x.type === 'file' ) {
					v = parseFloat( String( v === undefined ? '' : v ).replace( ',', '.' ) );
					return isFinite( v ) ? v : 0;
				}
				if ( x.type === 'calculation' ) {
					return x.id === f.id ? 0 : Calc.evaluate( x.formula, num, text, props.tables );
				}
				if ( x.options ) {
					sum = 0;
					( Array.isArray( v ) ? v : [ v ] ).forEach( function ( label ) {
						o = x.options.filter( function ( op ) {
							return op.label === label;
						} )[ 0 ];
						sum += o ? Number( o.calc ) || 0 : 0;
					} );
					return sum;
				}
				if ( x.type === 'date' ) {
					return Calc.dayNumber( v );
				}
				v = parseFloat( String( v === undefined ? x.default : v ).replace( ',', '.' ) );
				return isFinite( v ) ? v : 0;
			},
			text,
			props.tables
		);
		function text( id ) {
			var v = values[ id ];
			return Array.isArray( v ) ? v[ 0 ] || '' : v || '';
		}

		var chips = list.length ? list.map( function ( t, i ) {
			var label = t.t, cls = 'smokyforms-chip is-' + t.k;
			if ( t.k === 'ref' ) {
				label = byId[ t.t ] ? refLabel( props.fields, t.t ) || byId[ t.t ].label || t.t : refLabel( props.fields, t.t ) || sprintf( __( 'Missing field: %s', 'smokyforms' ), t.t );
				if ( ! byId[ t.t ] && ! refLabel( props.fields, t.t ) ) {
					cls += ' is-missing';
				}
			} else if ( t.k === 'op' && OP_LABELS[ t.t ] ) {
				label = OP_LABELS[ t.t ];
			}
			return el( 'span', { key: i, className: cls }, label );
		} ) : el( 'span', { className: 'smokyforms-chip-empty' }, __( 'Click fields and operators below to build the calculation.', 'smokyforms' ) );

		var lookupValue = lookupState[ 0 ];

		return el(
			'div',
			{ className: 'smokyforms-formula' },
			el(
				'div',
				{ className: 'smokyforms-formula-mode' },
				el( c.Button, { variant: mode[ 0 ] === 'click' ? 'primary' : 'tertiary', size: 'compact', onClick: function () {
					mode[ 1 ]( 'click' );
				} }, __( 'Build by clicking', 'smokyforms' ) ),
				el( c.Button, { variant: mode[ 0 ] === 'text' ? 'primary' : 'tertiary', size: 'compact', onClick: function () {
					mode[ 1 ]( 'text' );
				} }, __( 'Write the formula', 'smokyforms' ) )
			),
			mode[ 0 ] === 'click' ? el(
				Fragment,
				null,
				el( 'div', { className: 'smokyforms-chips', 'aria-live': 'polite' }, chips ),
				el(
					'div',
					{ className: 'smokyforms-formula-tools' },
					el( c.Button, { variant: 'secondary', size: 'compact', icon: 'undo', disabled: ! list.length, onClick: undo }, __( 'Undo', 'smokyforms' ) ),
					el( c.Button, { variant: 'tertiary', size: 'compact', isDestructive: true, disabled: ! list.length, onClick: function () {
						setFormula( '' );
					} }, __( 'Clear', 'smokyforms' ) )
				),
				el( 'h4', null, __( 'Fields', 'smokyforms' ) ),
				others.length ? el(
					'div',
					{ className: 'smokyforms-pad' },
					others.map( function ( x ) {
						return el( c.Button, { key: x.ref, variant: 'secondary', size: 'compact', className: 'smokyforms-pad-field', onClick: function () {
							add( '{' + x.ref + '}' );
						} }, x.label );
					} )
				) : el( 'p', { className: 'description' }, __( 'Add number, choice or date fields first; they appear here.', 'smokyforms' ) ),
				el( 'h4', null, __( 'Operators', 'smokyforms' ) ),
				el(
					'div',
					{ className: 'smokyforms-pad' },
					[ '+', '-', '*', '/', '(', ')', '%', '^', ',' ].map( function ( op ) {
						return el( c.Button, { key: op, variant: 'secondary', size: 'compact', className: 'smokyforms-pad-op', label: op === '^' ? __( 'Power', 'smokyforms' ) : op === '%' ? __( 'Remainder', 'smokyforms' ) : op === ',' ? __( 'Comma (between values of a function)', 'smokyforms' ) : undefined, showTooltip: [ '^', '%', ',' ].indexOf( op ) !== -1, onClick: function () {
							add( op );
						} }, OP_LABELS[ op ] || op );
					} )
				),
				el( 'h4', null, __( 'Number', 'smokyforms' ) ),
				el(
					'form',
					{ className: 'smokyforms-pad', onSubmit: function ( e ) {
						e.preventDefault();
						var n = String( numState[ 0 ] ).replace( ',', '.' ).trim();
						if ( n !== '' && isFinite( Number( n ) ) ) {
							add( n.charAt( 0 ) === '-' ? '(0 ' + n + ')' : n );
							numState[ 1 ]( '' );
						}
					} },
					el( 'input', { type: 'text', inputMode: 'decimal', value: numState[ 0 ], className: 'smokyforms-number-input', 'aria-label': __( 'Number', 'smokyforms' ), onChange: function ( e ) {
						numState[ 1 ]( e.target.value );
					} } ),
					el( c.Button, { variant: 'secondary', size: 'compact', type: 'submit' }, __( 'Add number', 'smokyforms' ) )
				),
				el( 'h4', null, __( 'Conditions', 'smokyforms' ) ),
				el(
					'div',
					{ className: 'smokyforms-pad' },
					[ '>', '<', '>=', '<=', '==', '!=', '&&', '||' ].map( function ( op ) {
						return el( c.Button, { key: op, variant: 'secondary', size: 'compact', className: 'smokyforms-pad-op', onClick: function () {
							add( op );
						} }, OP_LABELS[ op ] || op );
					} )
				),
				el( 'h4', null, __( 'Functions', 'smokyforms' ) ),
				el(
					'div',
					{ className: 'smokyforms-pad' },
					FUNCTIONS.map( function ( fn ) {
						return el( c.Button, { key: fn.name, variant: 'secondary', size: 'compact', label: fn.help, showTooltip: true, onClick: function () {
							add( fn.insert );
						} }, fn.label );
					} )
				),
				props.tables.length ? el(
					Fragment,
					null,
					el( 'h4', null, __( 'Price table', 'smokyforms' ) ),
					el(
						'div',
						{ className: 'smokyforms-lookup' },
						el( c.SelectControl, {
							label: __( 'Table', 'smokyforms' ),
							value: lookupValue.table,
							options: [ { value: '', label: '—' } ].concat( props.tables.map( function ( t ) {
								return { value: t.id, label: t.name || t.id };
							} ) ),
							onChange: function ( v ) {
								lookupState[ 1 ]( Object.assign( {}, lookupValue, { table: v } ) );
							},
							__nextHasNoMarginBottom: true,
						} ),
						el( c.SelectControl, {
							label: __( 'Row from the answer of', 'smokyforms' ),
							value: lookupValue.row,
							options: [ { value: '', label: '—' } ].concat( choiceFields.map( function ( x ) {
								return { value: x.id, label: x.label || x.id };
							} ) ),
							onChange: function ( v ) {
								lookupState[ 1 ]( Object.assign( {}, lookupValue, { row: v } ) );
							},
							__nextHasNoMarginBottom: true,
						} ),
						el( c.SelectControl, {
							label: __( 'Column from the answer of', 'smokyforms' ),
							value: lookupValue.col,
							options: [ { value: '', label: __( 'Only one column', 'smokyforms' ) } ].concat( choiceFields.map( function ( x ) {
								return { value: x.id, label: x.label || x.id };
							} ) ),
							onChange: function ( v ) {
								lookupState[ 1 ]( Object.assign( {}, lookupValue, { col: v } ) );
							},
							__nextHasNoMarginBottom: true,
						} ),
						el( c.Button, { variant: 'secondary', size: 'compact', disabled: ! lookupValue.table || ! lookupValue.row, onClick: function () {
							add( 'lookup(' + lookupValue.table + ', {' + lookupValue.row + '}' + ( lookupValue.col ? ', {' + lookupValue.col + '}' : '' ) + ')' );
						} }, __( 'Insert price', 'smokyforms' ) )
					)
				) : null
			) : el( c.TextareaControl, {
				label: __( 'Formula', 'smokyforms' ),
				help: __( 'Fields are written as {field_id}. Example: {price} * {quantity} + 5', 'smokyforms' ),
				value: formula,
				rows: 4,
				className: 'smokyforms-formula-text',
				onChange: setFormula,
				__nextHasNoMarginBottom: true,
			} ),
			el(
				'div',
				{ className: 'smokyforms-formula-status ' + ( problem ? 'is-error' : 'is-ok' ) },
				formula ? ( problem ? __( 'The formula is not complete yet. Check brackets, commas and operators.', 'smokyforms' ) : __( 'The formula is valid.', 'smokyforms' ) ) : null
			),
			refs.length || ! formula ? el(
				'details',
				{ className: 'smokyforms-test' },
				el( 'summary', null, __( 'Try it', 'smokyforms' ) ),
				refs.map( function ( id ) {
					var x = byId[ id ];
					if ( ( ! x && ! refLabel( props.fields, id ) ) || ( x && x.type === 'calculation' ) ) {
						return null;
					}
					if ( ! x || x.type === 'repeater' || x.type === 'file' ) {
						return el( c.TextControl, {
							key: id,
							label: refLabel( props.fields, id ),
							type: 'number',
							value: values[ id ] === undefined ? '' : values[ id ],
							onChange: function ( v ) {
								var next = Object.assign( {}, values );
								next[ id ] = v;
								setValues( next );
							},
							__nextHasNoMarginBottom: true,
						} );
					}
					if ( x.options ) {
						return el( c.SelectControl, {
							key: id,
							label: x.label || id,
							value: text( id ),
							options: [ { value: '', label: '—' } ].concat( x.options.map( function ( o ) {
								return { value: o.label, label: o.label };
							} ) ),
							onChange: function ( v ) {
								var next = Object.assign( {}, values );
								next[ id ] = v;
								setValues( next );
							},
							__nextHasNoMarginBottom: true,
						} );
					}
					return el( c.TextControl, {
						key: id,
						label: x.label || id,
						type: x.type === 'date' ? 'date' : 'text',
						value: values[ id ] === undefined ? x.default || '' : values[ id ],
						onChange: function ( v ) {
							var next = Object.assign( {}, values );
							next[ id ] = v;
							setValues( next );
						},
						__nextHasNoMarginBottom: true,
					} );
				} ),
				el( 'p', { className: 'smokyforms-test-result' }, __( 'Result:', 'smokyforms' ), ' ', el( 'strong', null, ( ( f.prefix || '' ) + Calc.format( preview, parseInt( f.decimals, 10 ) || 0, data.number ) + ( f.suffix || '' ) ).trim() ) )
			) : null
		);
	}

	/* Conditional logic */

	var LOGIC_OPS = [
		[ 'is', __( 'is', 'smokyforms' ) ],
		[ 'is_not', __( 'is not', 'smokyforms' ) ],
		[ 'empty', __( 'is empty', 'smokyforms' ) ],
		[ 'not_empty', __( 'is not empty', 'smokyforms' ) ],
		[ 'gt', __( 'is greater than', 'smokyforms' ) ],
		[ 'lt', __( 'is less than', 'smokyforms' ) ],
		[ 'contains', __( 'contains', 'smokyforms' ) ],
	];

	function LogicEditor( props ) {
		var f = props.field, logic = f.logic, candidates = props.fields.filter( function ( x ) {
			return x.id !== f.id && TYPES[ x.type ] && TYPES[ x.type ].input;
		} );
		function set( next ) {
			props.onChange( { logic: next } );
		}
		function setRule( i, patch ) {
			var next = clone( logic );
			Object.assign( next.rules[ i ], patch );
			set( next );
		}
		var toggle = el( c.ToggleControl, {
			label: __( 'Conditional logic', 'smokyforms' ),
			help: logic ? null : __( 'Show or hide this field depending on other answers.', 'smokyforms' ),
			checked: !! logic,
			disabled: ! candidates.length && ! logic,
			onChange: function ( v ) {
				set( v ? { enabled: true, action: 'show', match: 'all', rules: [ { field: candidates.length ? candidates[ 0 ].id : '', op: 'is', value: '' } ] } : null );
			},
			__nextHasNoMarginBottom: true,
		} );
		if ( ! logic ) {
			return el( 'div', { className: 'smokyforms-logic' }, toggle );
		}
		return el(
			'div',
			{ className: 'smokyforms-logic' },
			toggle,
			el(
				'div',
				{ className: 'smokyforms-row' },
				el( c.SelectControl, {
					label: __( 'Action', 'smokyforms' ),
					hideLabelFromVision: true,
					value: logic.action,
					options: [ { value: 'show', label: __( 'Show this field', 'smokyforms' ) }, { value: 'hide', label: __( 'Hide this field', 'smokyforms' ) } ],
					onChange: function ( v ) {
						set( Object.assign( clone( logic ), { action: v } ) );
					},
					__nextHasNoMarginBottom: true,
				} ),
				el( c.SelectControl, {
					label: __( 'Match', 'smokyforms' ),
					hideLabelFromVision: true,
					value: logic.match,
					options: [ { value: 'all', label: __( 'if all rules match', 'smokyforms' ) }, { value: 'any', label: __( 'if any rule matches', 'smokyforms' ) } ],
					onChange: function ( v ) {
						set( Object.assign( clone( logic ), { match: v } ) );
					},
					__nextHasNoMarginBottom: true,
				} )
			),
			logic.rules.map( function ( r, i ) {
				var target = props.fields.filter( function ( x ) {
						return x.id === r.field;
					} )[ 0 ],
					needsValue = r.op !== 'empty' && r.op !== 'not_empty',
					valueControl = null;
				if ( needsValue && target && ( target.options || target.type === 'rating' ) && ( r.op === 'is' || r.op === 'is_not' ) ) {
					valueControl = el( c.SelectControl, {
						label: __( 'Value', 'smokyforms' ),
						hideLabelFromVision: true,
						value: r.value,
						options: [ { value: '', label: '—' } ].concat( target.options ? target.options.map( function ( o ) {
							return { value: o.label, label: o.label };
						} ) : new Array( target.max || 5 ).join( ',' ).split( ',' ).map( function ( u, n ) {
							return { value: String( n + 1 ), label: String( n + 1 ) };
						} ) ),
						onChange: function ( v ) {
							setRule( i, { value: v } );
						},
						__nextHasNoMarginBottom: true,
					} );
				} else if ( needsValue ) {
					valueControl = el( c.TextControl, {
						label: __( 'Value', 'smokyforms' ),
						hideLabelFromVision: true,
						placeholder: __( 'Value', 'smokyforms' ),
						value: r.value,
						onChange: function ( v ) {
							setRule( i, { value: v } );
						},
						__nextHasNoMarginBottom: true,
					} );
				}
				return el(
					'div',
					{ key: i, className: 'smokyforms-logic-rule' },
					el( c.SelectControl, {
						label: __( 'Field', 'smokyforms' ),
						hideLabelFromVision: true,
						value: r.field,
						options: ( target ? [] : [ { value: r.field, label: sprintf( __( 'Missing field: %s', 'smokyforms' ), r.field ) } ] ).concat( candidates.map( function ( x ) {
							return { value: x.id, label: x.label || x.id };
						} ) ),
						onChange: function ( v ) {
							setRule( i, { field: v, value: '' } );
						},
						__nextHasNoMarginBottom: true,
					} ),
					el( c.SelectControl, {
						label: __( 'Comparison', 'smokyforms' ),
						hideLabelFromVision: true,
						value: r.op,
						options: LOGIC_OPS.map( function ( o ) {
							return { value: o[ 0 ], label: o[ 1 ] };
						} ),
						onChange: function ( v ) {
							setRule( i, { op: v } );
						},
						__nextHasNoMarginBottom: true,
					} ),
					valueControl,
					el( c.Button, { icon: 'no-alt', size: 'small', label: __( 'Remove rule', 'smokyforms' ), disabled: logic.rules.length < 2, onClick: function () {
						var next = clone( logic );
						next.rules.splice( i, 1 );
						set( next );
					} } )
				);
			} ),
			el( c.Button, { variant: 'secondary', size: 'compact', onClick: function () {
				var next = clone( logic );
				next.rules.push( { field: candidates.length ? candidates[ 0 ].id : '', op: 'is', value: '' } );
				set( next );
			} }, __( 'Add rule', 'smokyforms' ) )
		);
	}

	/* Fields of a repeating group */

	function SubFieldsEditor( props ) {
		var subs = props.field.subfields || [];
		function set( list ) {
			props.onChange( { subfields: list } );
		}
		function update( i, patch ) {
			var list = clone( subs );
			if ( patch.type && patch.type !== list[ i ].type ) {
				var fresh = newSub( patch.type, list.filter( function ( x, j ) {
					return j !== i;
				} ) );
				fresh.id = list[ i ].id;
				fresh.label = list[ i ].label;
				fresh.required = list[ i ].required;
				fresh.width = list[ i ].width;
				fresh._autoId = list[ i ]._autoId;
				list[ i ] = fresh;
			} else {
				Object.assign( list[ i ], patch );
			}
			set( list );
		}
		return el(
			'div',
			{ className: 'smokyforms-subfields' },
			subs.map( function ( sub, i ) {
				return el(
					'div',
					{ key: i, className: 'smokyforms-subfield' },
					el(
						'div',
						{ className: 'smokyforms-row' },
						el( c.TextControl, { label: __( 'Label', 'smokyforms' ), value: sub.label, onChange: function ( v ) {
							var patch = { label: v };
							if ( sub._autoId && slug( v ) ) {
								patch.id = uid( subs.filter( function ( x, j ) {
									return j !== i;
								} ), slug( v ) );
							}
							update( i, patch );
						}, __nextHasNoMarginBottom: true } ),
						el( c.SelectControl, {
							label: __( 'Type', 'smokyforms' ),
							value: sub.type,
							options: SUB_TYPES.map( function ( t ) {
								return { value: t, label: TYPES[ t ] ? TYPES[ t ].label : t };
							} ),
							onChange: function ( v ) {
								update( i, { type: v } );
							},
							__nextHasNoMarginBottom: true,
						} )
					),
					sub.type === 'select' ? el( OptionsEditor, { field: sub, onChange: function ( patch ) {
						update( i, patch );
					} } ) : null,
					el(
						'div',
						{ className: 'smokyforms-subfield-actions' },
						el( c.ToggleControl, { label: __( 'Required', 'smokyforms' ), checked: !! sub.required, onChange: function ( v ) {
							update( i, { required: v } );
						}, __nextHasNoMarginBottom: true } ),
						el( c.ToggleControl, { label: __( 'Half width', 'smokyforms' ), checked: sub.width === 'half', onChange: function ( v ) {
							update( i, { width: v ? 'half' : 'full' } );
						}, __nextHasNoMarginBottom: true } ),
						el( 'span', { className: 'smokyforms-subfield-id', title: '{' + props.field.id + '.' + sub.id + '}' }, '{' + props.field.id + '.' + sub.id + '}' ),
						el( 'span', { className: 'smokyforms-subfield-buttons' }, el( c.Button, { icon: 'arrow-up-alt2', size: 'small', label: __( 'Move up', 'smokyforms' ), disabled: i === 0, onClick: function () {
							var list = clone( subs );
							list.splice( i - 1, 0, list.splice( i, 1 )[ 0 ] );
							set( list );
						} } ),
						el( c.Button, { icon: 'trash', size: 'small', isDestructive: true, label: __( 'Delete', 'smokyforms' ), disabled: subs.length < 2, onClick: function () {
							var list = clone( subs );
							list.splice( i, 1 );
							set( list );
						} } ) )
					)
				);
			} ),
			el( c.Button, { variant: 'secondary', size: 'compact', onClick: function () {
				set( subs.concat( [ newSub( 'text', subs ) ] ) );
			} }, __( 'Add field', 'smokyforms' ) )
		);
	}

	/* Field settings */

	function FieldSettings( props ) {
		var f = props.field;
		if ( ! f ) {
			return el( 'div', { className: 'smokyforms-settings-empty' }, el( 'p', null, __( 'Select a field to change its settings.', 'smokyforms' ) ) );
		}
		var input = TYPES[ f.type ] && TYPES[ f.type ].input;
		function set( patch ) {
			props.onChange( patch );
		}
		var controls = [];

		controls.push( el( c.TextControl, { key: 'label', label: f.type === 'heading' ? __( 'Heading', 'smokyforms' ) : f.type === 'page' ? __( 'Title of the next page', 'smokyforms' ) : __( 'Label', 'smokyforms' ), help: f.type === 'page' ? __( 'Optional. Everything below this break, up to the next one, is one page of the form.', 'smokyforms' ) : null, value: f.label, onChange: function ( v ) {
			set( { label: v } );
			// A new field takes its ID from the label until the ID is changed by hand or the form is saved.
			if ( f._autoId && slug( v ) && f.type !== 'page' ) {
				props.onRename( uid( props.fields.filter( function ( x ) {
					return x.id !== f.id;
				} ), slug( v ) ), true );
			}
		}, __nextHasNoMarginBottom: true } ) );

		if ( input && f.type !== 'calculation' && f.type !== 'hidden' ) {
			controls.push( el( c.ToggleControl, { key: 'required', label: __( 'Required', 'smokyforms' ), checked: !! f.required, onChange: function ( v ) {
				set( { required: v } );
			}, __nextHasNoMarginBottom: true } ) );
		}
		if ( [ 'text', 'textarea', 'email', 'phone', 'url', 'number', 'select' ].indexOf( f.type ) !== -1 ) {
			controls.push( el( c.TextControl, { key: 'placeholder', label: f.type === 'select' ? __( 'Text before a choice is made', 'smokyforms' ) : __( 'Placeholder', 'smokyforms' ), value: f.placeholder, onChange: function ( v ) {
				set( { placeholder: v } );
			}, __nextHasNoMarginBottom: true } ) );
		}
		if ( [ 'text', 'textarea', 'email', 'phone', 'url', 'number', 'date', 'hidden' ].indexOf( f.type ) !== -1 ) {
			controls.push( el( c.TextControl, { key: 'default', label: __( 'Default value', 'smokyforms' ), type: f.type === 'date' ? 'date' : 'text', value: f.default, onChange: function ( v ) {
				set( { default: v } );
			}, __nextHasNoMarginBottom: true } ) );
		}
		if ( f.type === 'select' || f.type === 'radio' || f.type === 'checkbox' ) {
			controls.push( el( 'div', { key: 'options', className: 'components-base-control' }, el( 'span', { className: 'smokyforms-control-label' }, __( 'Choices', 'smokyforms' ) ), el( OptionsEditor, { field: f, onChange: set } ) ) );
		}
		if ( f.type === 'number' ) {
			controls.push( el(
				'div',
				{ key: 'range', className: 'smokyforms-row' },
				[ [ 'min', __( 'Smallest', 'smokyforms' ) ], [ 'max', __( 'Largest', 'smokyforms' ) ], [ 'step', __( 'Step', 'smokyforms' ) ] ].map( function ( p ) {
					return el( c.TextControl, { key: p[ 0 ], label: p[ 1 ], type: 'number', value: f[ p[ 0 ] ], onChange: function ( v ) {
						var patch = {};
						patch[ p[ 0 ] ] = v === '' ? '' : parseFloat( v );
						set( patch );
					}, __nextHasNoMarginBottom: true } );
				} )
			) );
		}
		if ( f.type === 'consent' ) {
			controls.push( el( c.TextareaControl, { key: 'text', label: __( 'Text next to the checkbox', 'smokyforms' ), help: __( 'Links are allowed, for example to your privacy policy.', 'smokyforms' ), value: f.text, onChange: function ( v ) {
				set( { text: v } );
			}, __nextHasNoMarginBottom: true } ) );
		}
		if ( f.type === 'html' ) {
			controls.push( el( c.TextareaControl, { key: 'content', label: __( 'Text', 'smokyforms' ), help: __( 'Basic HTML is allowed.', 'smokyforms' ), rows: 6, value: f.content, onChange: function ( v ) {
				set( { content: v } );
			}, __nextHasNoMarginBottom: true } ) );
		}
		if ( f.type === 'rating' ) {
			controls.push( el( c.SelectControl, {
				key: 'max',
				label: __( 'Number of stars', 'smokyforms' ),
				value: String( f.max || 5 ),
				options: [ 3, 4, 5, 6, 7, 8, 9, 10 ].map( function ( n ) {
					return { value: String( n ), label: String( n ) };
				} ),
				onChange: function ( v ) {
					set( { max: parseInt( v, 10 ) } );
				},
				__nextHasNoMarginBottom: true,
			} ) );
		}
		if ( f.type === 'file' ) {
			controls.push( el( c.TextControl, { key: 'extensions', label: __( 'Allowed file types', 'smokyforms' ), help: __( 'File extensions, separated by commas. Only types WordPress itself allows can be used.', 'smokyforms' ), value: f.extensions, onChange: function ( v ) {
				set( { extensions: v } );
			}, __nextHasNoMarginBottom: true } ) );
			controls.push( el(
				'div',
				{ key: 'limits', className: 'smokyforms-row' },
				el( c.TextControl, { label: __( 'Largest file (MB)', 'smokyforms' ), help: sprintf( __( 'Your server allows up to %s MB.', 'smokyforms' ), data.upload.maxSize ), type: 'number', min: 0.1, max: data.upload.maxSize, step: 0.1, value: f.max_size, onChange: function ( v ) {
					set( { max_size: v === '' ? '' : Math.min( parseFloat( v ), data.upload.maxSize ) } );
				}, __nextHasNoMarginBottom: true } ),
				el( c.TextControl, { label: __( 'Number of files', 'smokyforms' ), type: 'number', min: 1, max: 20, value: f.max_files, onChange: function ( v ) {
					set( { max_files: Math.max( 1, Math.min( 20, parseInt( v, 10 ) || 1 ) ) } );
				}, __nextHasNoMarginBottom: true } )
			) );
		}
		if ( f.type === 'file' || f.type === 'signature' ) {
			controls.push( el( c.ToggleControl, { key: 'attach', label: __( 'Attach to the notification email', 'smokyforms' ), help: __( 'The email always links to the file. Large attachments can be refused by mail servers.', 'smokyforms' ), checked: !! f.attach, onChange: function ( v ) {
				set( { attach: v } );
			}, __nextHasNoMarginBottom: true } ) );
		}
		if ( f.type === 'repeater' ) {
			controls.push( el( 'div', { key: 'subfields', className: 'components-base-control' }, el( 'span', { className: 'smokyforms-control-label' }, __( 'Fields of each row', 'smokyforms' ) ), el( SubFieldsEditor, { field: f, onChange: set } ) ) );
			controls.push( el(
				'div',
				{ key: 'rows', className: 'smokyforms-row' },
				el( c.TextControl, { label: __( 'Fewest rows', 'smokyforms' ), type: 'number', min: 0, max: 50, value: f.min_rows, onChange: function ( v ) {
					set( { min_rows: Math.max( 0, Math.min( 50, parseInt( v, 10 ) || 0 ) ) } );
				}, __nextHasNoMarginBottom: true } ),
				el( c.TextControl, { label: __( 'Most rows', 'smokyforms' ), type: 'number', min: 1, max: 50, value: f.max_rows, onChange: function ( v ) {
					set( { max_rows: Math.max( 1, Math.min( 50, parseInt( v, 10 ) || 1 ) ) } );
				}, __nextHasNoMarginBottom: true } )
			) );
			controls.push( el( c.TextControl, { key: 'add', label: __( 'Text of the add button', 'smokyforms' ), placeholder: __( 'Add another', 'smokyforms' ), value: f.add_label, onChange: function ( v ) {
				set( { add_label: v } );
			}, __nextHasNoMarginBottom: true } ) );
		}
		if ( f.type === 'page' ) {
			controls.push( el(
				'div',
				{ key: 'buttons', className: 'smokyforms-row' },
				el( c.TextControl, { label: __( 'Next button', 'smokyforms' ), placeholder: __( 'Next', 'smokyforms' ), value: f.next_label, onChange: function ( v ) {
					set( { next_label: v } );
				}, __nextHasNoMarginBottom: true } ),
				el( c.TextControl, { label: __( 'Back button', 'smokyforms' ), placeholder: __( 'Previous', 'smokyforms' ), value: f.prev_label, onChange: function ( v ) {
					set( { prev_label: v } );
				}, __nextHasNoMarginBottom: true } )
			) );
		}
		if ( f.type === 'calculation' ) {
			controls.push( el( 'div', { key: 'formula', className: 'components-base-control' }, el( 'span', { className: 'smokyforms-control-label' }, __( 'Calculation', 'smokyforms' ) ), el( FormulaEditor, { field: f, fields: props.fields, tables: props.tables, onChange: set } ) ) );
			controls.push( el(
				'div',
				{ key: 'format', className: 'smokyforms-row' },
				el( c.TextControl, { label: __( 'Before the result', 'smokyforms' ), help: __( 'For example $', 'smokyforms' ), value: f.prefix, onChange: function ( v ) {
					set( { prefix: v } );
				}, __nextHasNoMarginBottom: true } ),
				el( c.TextControl, { label: __( 'After the result', 'smokyforms' ), help: __( 'For example € or kg', 'smokyforms' ), value: f.suffix, onChange: function ( v ) {
					set( { suffix: v } );
				}, __nextHasNoMarginBottom: true } ),
				el( c.TextControl, { label: __( 'Decimals', 'smokyforms' ), type: 'number', min: 0, max: 6, value: f.decimals, onChange: function ( v ) {
					set( { decimals: Math.max( 0, Math.min( 6, parseInt( v, 10 ) || 0 ) ) } );
				}, __nextHasNoMarginBottom: true } )
			) );
			controls.push( el( c.ToggleControl, { key: 'visible', label: __( 'Show the result in the form', 'smokyforms' ), help: __( 'Switch off for a step that only other calculations use. The result is still in the email.', 'smokyforms' ), checked: f.visible !== false, onChange: function ( v ) {
				set( { visible: v } );
			}, __nextHasNoMarginBottom: true } ) );
		}
		if ( f.type !== 'hidden' && f.type !== 'heading' && f.type !== 'html' && f.type !== 'page' ) {
			controls.push( el( c.TextControl, { key: 'help', label: __( 'Help text', 'smokyforms' ), value: f.help, onChange: function ( v ) {
				set( { help: v } );
			}, __nextHasNoMarginBottom: true } ) );
		}
		if ( [ 'hidden', 'heading', 'html', 'page', 'repeater' ].indexOf( f.type ) === -1 ) {
			controls.push( el( c.SelectControl, {
				key: 'width',
				label: __( 'Width', 'smokyforms' ),
				value: f.width,
				options: [ { value: 'full', label: __( 'Full width', 'smokyforms' ) }, { value: 'half', label: __( 'Half width', 'smokyforms' ) } ],
				onChange: function ( v ) {
					set( { width: v } );
				},
				__nextHasNoMarginBottom: true,
			} ) );
		}
		if ( f.type !== 'page' && f.type !== 'hidden' ) {
			controls.push( el( LogicEditor, { key: 'logic', field: f, fields: props.fields, onChange: set } ) );
		}
		if ( input ) {
			controls.push( el(
				'div',
				{ key: 'id', className: 'smokyforms-field-id' },
				el( c.TextControl, {
					label: __( 'Field ID', 'smokyforms' ),
					help: sprintf( __( 'Used in formulas as {%1$s} and in emails as {field:%1$s}. Changing it updates the formulas of this form.', 'smokyforms' ), f.id ),
					value: f.id,
					onChange: function ( v ) {
						props.onRename( v.toLowerCase().replace( /[^a-z0-9_]/g, '' ), false );
					},
					__nextHasNoMarginBottom: true,
				} )
			) );
		}
		return el(
			'div',
			{ className: 'smokyforms-field-settings' },
			el( 'h2', null, ( TYPES[ f.type ] || { label: f.type } ).label ),
			controls
		);
	}

	/* Price tables */

	function TablesTab( props ) {
		var tables = props.tables, choiceFields = props.fields.filter( function ( x ) {
			return x.options;
		} );
		function update( i, patch ) {
			var list = clone( tables );
			Object.assign( list[ i ], patch );
			// Keep the grid the size of rows × columns.
			list[ i ].cells = list[ i ].rows.map( function ( r, ri ) {
				var width = Math.max( 1, list[ i ].cols.length );
				var row = ( list[ i ].cells[ ri ] || [] ).slice( 0, width );
				while ( row.length < width ) {
					row.push( 0 );
				}
				return row;
			} );
			props.onChange( list );
		}
		function newId() {
			var n = tables.length + 1, ids = tables.map( function ( t ) {
				return t.id;
			} );
			while ( ids.indexOf( 'table' + n ) !== -1 ) {
				n++;
			}
			return 'table' + n;
		}
		return el(
			'div',
			{ className: 'smokyforms-tables' },
			el( 'p', null, __( 'A price table gives a value for each answer, or for each combination of two answers (for example zone and parcel size). Use it in a calculation with "Price table". Row and column names must be written exactly like the choices of the fields.', 'smokyforms' ) ),
			tables.map( function ( t, i ) {
				return el(
					'div',
					{ key: t.id, className: 'smokyforms-table card' },
					el(
						'div',
						{ className: 'smokyforms-row' },
						el( c.TextControl, { label: __( 'Name', 'smokyforms' ), value: t.name, onChange: function ( v ) {
							update( i, { name: v } );
						}, __nextHasNoMarginBottom: true } ),
						el( 'p', { className: 'smokyforms-table-id' }, sprintf( __( 'ID: %s', 'smokyforms' ), t.id ) )
					),
					el(
						'div',
						{ className: 'smokyforms-row' },
						el( c.SelectControl, {
							label: __( 'Rows from the choices of', 'smokyforms' ),
							value: '',
							options: [ { value: '', label: '—' } ].concat( choiceFields.map( function ( x ) {
								return { value: x.id, label: x.label || x.id };
							} ) ),
							onChange: function ( v ) {
								var x = choiceFields.filter( function ( y ) {
									return y.id === v;
								} )[ 0 ];
								if ( x ) {
									update( i, { rows: x.options.map( function ( o ) {
										return o.label;
									} ) } );
								}
							},
							__nextHasNoMarginBottom: true,
						} ),
						el( c.SelectControl, {
							label: __( 'Columns from the choices of', 'smokyforms' ),
							value: '',
							options: [ { value: '', label: '—' } ].concat( choiceFields.map( function ( x ) {
								return { value: x.id, label: x.label || x.id };
							} ) ),
							onChange: function ( v ) {
								var x = choiceFields.filter( function ( y ) {
									return y.id === v;
								} )[ 0 ];
								if ( x ) {
									update( i, { cols: x.options.map( function ( o ) {
										return o.label;
									} ) } );
								}
							},
							__nextHasNoMarginBottom: true,
						} )
					),
					el(
						'div',
						{ className: 'smokyforms-grid-wrap' },
						el(
							'table',
							{ className: 'smokyforms-grid' },
							el(
								'thead',
								null,
								el(
									'tr',
									null,
									el( 'th', null ),
									( t.cols.length ? t.cols : [ '' ] ).map( function ( col, ci ) {
										return el( 'th', { key: ci }, t.cols.length ? el( 'input', { type: 'text', value: col, 'aria-label': __( 'Column name', 'smokyforms' ), onChange: function ( e ) {
											var cols = t.cols.slice();
											cols[ ci ] = e.target.value;
											update( i, { cols: cols } );
										} } ) : __( 'Value', 'smokyforms' ) );
									} )
								)
							),
							el(
								'tbody',
								null,
								t.rows.map( function ( row, ri ) {
									return el(
										'tr',
										{ key: ri },
										el( 'th', null, el( 'input', { type: 'text', value: row, 'aria-label': __( 'Row name', 'smokyforms' ), onChange: function ( e ) {
											var rows = t.rows.slice();
											rows[ ri ] = e.target.value;
											update( i, { rows: rows } );
										} } ) ),
										( t.cells[ ri ] || [] ).map( function ( cell, ci ) {
											return el( 'td', { key: ci }, el( 'input', { type: 'number', step: 'any', value: cell, 'aria-label': __( 'Value', 'smokyforms' ), onChange: function ( e ) {
												var cells = clone( t.cells );
												cells[ ri ][ ci ] = e.target.value === '' ? 0 : parseFloat( e.target.value );
												update( i, { cells: cells } );
											} } ) );
										} )
									);
								} )
							)
						)
					),
					el(
						'div',
						{ className: 'smokyforms-table-actions' },
						el( c.Button, { variant: 'secondary', size: 'compact', onClick: function () {
							update( i, { rows: t.rows.concat( [ sprintf( __( 'Row %d', 'smokyforms' ), t.rows.length + 1 ) ] ) } );
						} }, __( 'Add row', 'smokyforms' ) ),
						el( c.Button, { variant: 'secondary', size: 'compact', onClick: function () {
							update( i, { cols: t.cols.concat( [ sprintf( __( 'Column %d', 'smokyforms' ), t.cols.length + 1 ) ] ) } );
						} }, __( 'Add column', 'smokyforms' ) ),
						el( c.Button, { variant: 'tertiary', size: 'compact', disabled: ! t.rows.length, onClick: function () {
							update( i, { rows: t.rows.slice( 0, -1 ) } );
						} }, __( 'Remove last row', 'smokyforms' ) ),
						el( c.Button, { variant: 'tertiary', size: 'compact', disabled: ! t.cols.length, onClick: function () {
							update( i, { cols: t.cols.slice( 0, -1 ) } );
						} }, __( 'Remove last column', 'smokyforms' ) ),
						el( c.Button, { variant: 'tertiary', size: 'compact', isDestructive: true, onClick: function () {
							var list = clone( tables );
							list.splice( i, 1 );
							props.onChange( list );
						} }, __( 'Delete table', 'smokyforms' ) )
					)
				);
			} ),
			el( c.Button, { variant: 'primary', onClick: function () {
				props.onChange( tables.concat( [ { id: newId(), name: __( 'Price table', 'smokyforms' ), rows: [ __( 'Row 1', 'smokyforms' ) ], cols: [], cells: [ [ 0 ] ] } ] ) );
			} }, __( 'Add price table', 'smokyforms' ) )
		);
	}

	/* Form settings */

	/* Text with smart tags: a button lists the tags and inserts one where the cursor is. */

	function TagInput( props ) {
		var ref = useRef( null ), open = useState( false ),
			fields = props.fields.filter( function ( x ) {
				return TYPES[ x.type ] && TYPES[ x.type ].input && ( ! props.only || props.only.indexOf( x.type ) !== -1 );
			} ),
			general = Object.keys( data.tags ).filter( function ( tag ) {
				if ( props.only ) {
					return [ '{admin_email}', '{user_email}', '{author_email}' ].indexOf( tag ) !== -1;
				}
				return props.allFields || tag !== '{all_fields}';
			} ),
			id = 'smokyforms-tags-' + props.name;
		function insert( tag ) {
			var input = ref.current, value = props.value || '', start = value.length, end = value.length;
			if ( input && typeof input.selectionStart === 'number' && document.activeElement === input ) {
				start = input.selectionStart;
				end = input.selectionEnd;
			} else if ( input && input.getAttribute( 'data-pos' ) ) {
				start = end = Math.min( value.length, parseInt( input.getAttribute( 'data-pos' ), 10 ) );
			}
			if ( props.separator && start === value.length && value.trim() !== '' && ! /[,\s]$/.test( value ) ) {
				tag = props.separator + tag;
			} else if ( ! props.separator && start > 0 && ! /[\s(#>]/.test( value.charAt( start - 1 ) ) ) {
				tag = ' ' + tag;
			}
			props.onChange( value.slice( 0, start ) + tag + value.slice( end ) );
			open[ 1 ]( false );
			setTimeout( function () {
				if ( input ) {
					input.focus();
					input.setSelectionRange( start + tag.length, start + tag.length );
				}
			}, 0 );
		}
		function remember( e ) {
			e.target.setAttribute( 'data-pos', String( e.target.selectionStart ) );
		}
		var attrs = { id: id, ref: ref, className: props.multiline ? 'components-textarea-control__input' : 'components-text-control__input', value: props.value || '', rows: props.rows, onKeyUp: remember, onClick: remember, onBlur: remember, onChange: function ( e ) {
			props.onChange( e.target.value );
		} };
		return el(
			'div',
			{ className: 'components-base-control smokyforms-tag-input' },
			el(
				'div',
				{ className: 'smokyforms-tag-input-head' },
				el( 'label', { className: 'components-base-control__label', htmlFor: id }, props.label ),
				el( c.Button, { variant: 'link', className: 'smokyforms-tag-toggle', 'aria-expanded': open[ 0 ], onClick: function () {
					open[ 1 ]( ! open[ 0 ] );
				} }, open[ 0 ] ? __( 'Hide smart tags', 'smokyforms' ) : __( 'Show smart tags', 'smokyforms' ) )
			),
			open[ 0 ] ? el(
				'div',
				{ className: 'smokyforms-tag-list' },
				fields.length ? el( 'strong', null, __( 'Fields', 'smokyforms' ) ) : null,
				fields.map( function ( x ) {
					return el( c.Button, { key: x.id, variant: 'secondary', size: 'compact', onClick: function () {
						insert( '{field:' + x.id + '}' );
					} }, x.label || x.id );
				} ),
				general.length ? el( 'strong', null, __( 'Other', 'smokyforms' ) ) : null,
				general.map( function ( tag ) {
					return el( c.Button, { key: tag, variant: 'secondary', size: 'compact', title: tag, onClick: function () {
						insert( tag );
					} }, data.tags[ tag ] );
				} )
			) : null,
			el( props.multiline ? 'textarea' : 'input', attrs ),
			props.help ? el( 'p', { className: 'components-base-control__help' }, props.help ) : null
		);
	}

	/* Address field that suggests the site's pages and posts while typing. */

	function PagePicker( props ) {
		var open = useState( false ), items = useState( [] ), active = useState( -1 ), timer = useRef( null ), id = 'smokyforms-redirect';
		function search( term ) {
			clearTimeout( timer.current );
			timer.current = setTimeout( function () {
				// A typed address is not searched; a word is.
				if ( /^(https?:)?\/\//.test( term ) ) {
					items[ 1 ]( [] );
					return;
				}
				wp.apiFetch( { path: wp.url.addQueryArgs( '/wp/v2/search', { search: term, type: 'post', subtype: 'any', per_page: 10 } ) } ).then( function ( list ) {
					items[ 1 ]( list || [] );
					active[ 1 ]( -1 );
				} ).catch( function () {
					items[ 1 ]( [] );
				} );
			}, 200 );
		}
		function pick( item ) {
			props.onChange( item.url );
			open[ 1 ]( false );
		}
		return el(
			'div',
			{ className: 'components-base-control smokyforms-page-picker' },
			el( 'label', { className: 'components-base-control__label', htmlFor: id }, props.label ),
			el( 'input', {
				id: id,
				type: 'text',
				className: 'components-text-control__input',
				value: props.value || '',
				placeholder: __( 'Search pages or paste an address', 'smokyforms' ),
				autoComplete: 'off',
				role: 'combobox',
				'aria-expanded': open[ 0 ] && items[ 0 ].length > 0,
				'aria-controls': id + '-list',
				onFocus: function () {
					open[ 1 ]( true );
					search( /^(https?:)?\/\//.test( props.value || '' ) ? '' : props.value || '' );
				},
				onBlur: function () {
					setTimeout( function () {
						open[ 1 ]( false );
					}, 150 );
				},
				onChange: function ( e ) {
					props.onChange( e.target.value );
					open[ 1 ]( true );
					search( e.target.value );
				},
				onKeyDown: function ( e ) {
					var n = items[ 0 ].length;
					if ( ! open[ 0 ] || ! n ) {
						return;
					}
					if ( e.key === 'ArrowDown' || e.key === 'ArrowUp' ) {
						e.preventDefault();
						active[ 1 ]( ( active[ 0 ] + ( e.key === 'ArrowDown' ? 1 : n - 1 ) ) % n );
					} else if ( e.key === 'Enter' && active[ 0 ] > -1 ) {
						e.preventDefault();
						pick( items[ 0 ][ active[ 0 ] ] );
					} else if ( e.key === 'Escape' ) {
						open[ 1 ]( false );
					}
				},
			} ),
			open[ 0 ] && items[ 0 ].length ? el(
				'ul',
				{ id: id + '-list', className: 'smokyforms-page-list', role: 'listbox' },
				items[ 0 ].map( function ( item, i ) {
					return el(
						'li',
						{ key: item.id + '-' + item.subtype, role: 'option', 'aria-selected': i === active[ 0 ], className: i === active[ 0 ] ? 'is-active' : '', onMouseDown: function ( e ) {
							e.preventDefault();
							pick( item );
						} },
						el( 'strong', null, item.title ? wp.htmlEntities.decodeEntities( item.title ) : item.url ),
						el( 'span', null, item.url )
					);
				} )
			) : null,
			props.help ? el( 'p', { className: 'components-base-control__help' }, props.help ) : null
		);
	}

	/* Button colours: empty means the theme's own button style. */

	function ButtonPanel( props ) {
		var b = Object.assign( { bg: '', bg_hover: '', text: '', text_hover: '' }, props.settings.button || {} ),
			hover = useState( false ),
			style = hover[ 0 ] ? { background: b.bg_hover || b.bg || undefined, borderColor: b.bg_hover || b.bg || undefined, color: b.text_hover || b.text || undefined } : { background: b.bg || undefined, borderColor: b.bg || undefined, color: b.text || undefined };
		function color( key, label ) {
			return el(
				'div',
				{ className: 'components-base-control smokyforms-color', key: key },
				el( 'span', { className: 'components-base-control__label' }, label ),
				el( c.Dropdown, {
					popoverProps: { placement: 'bottom-start' },
					renderToggle: function ( t ) {
						return el( c.Button, { variant: 'secondary', className: 'smokyforms-color-toggle', 'aria-expanded': t.isOpen, onClick: t.onToggle },
							el( c.ColorIndicator, { colorValue: b[ key ] || 'transparent', className: b[ key ] ? '' : 'is-empty' } ),
							b[ key ] || __( 'Theme', 'smokyforms' )
						);
					},
					renderContent: function () {
						return el( 'div', { className: 'smokyforms-color-popover' },
							el( c.ColorPicker, { color: b[ key ] || '#000000', enableAlpha: false, onChange: function ( v ) {
								var next = Object.assign( {}, b );
								next[ key ] = v;
								props.onChange( next );
							} } ),
							b[ key ] ? el( c.Button, { variant: 'link', onClick: function () {
								var next = Object.assign( {}, b );
								next[ key ] = '';
								props.onChange( next );
							} }, __( 'Use the theme style', 'smokyforms' ) ) : null
						);
					},
				} )
			);
		}
		return el(
			c.PanelBody,
			{ title: __( 'Button style', 'smokyforms' ), initialOpen: false },
			el( 'p', { className: 'description' }, __( 'By default the buttons look like the buttons of your theme. Choose colours only if you want them different.', 'smokyforms' ) ),
			el( 'div', { className: 'smokyforms-color-grid' },
				color( 'bg', __( 'Background', 'smokyforms' ) ),
				color( 'bg_hover', __( 'Background on hover', 'smokyforms' ) ),
				color( 'text', __( 'Text', 'smokyforms' ) ),
				color( 'text_hover', __( 'Text on hover', 'smokyforms' ) )
			),
			el( 'div', { className: 'smokyforms-button-preview' },
				el( 'span', { className: 'components-base-control__label' }, __( 'Preview', 'smokyforms' ) ),
				el( 'button', { type: 'button', className: 'button button-primary button-large', style: style, onMouseEnter: function () {
					hover[ 1 ]( true );
				}, onMouseLeave: function () {
					hover[ 1 ]( false );
				} }, props.settings.submit_label )
			),
			b.bg || b.bg_hover || b.text || b.text_hover ? el( c.Button, { variant: 'link', isDestructive: true, onClick: function () {
				props.onChange( { bg: '', bg_hover: '', text: '', text_hover: '' } );
			} }, __( 'Use the theme style', 'smokyforms' ) ) : null
		);
	}

	function SettingsTab( props ) {
		var s = props.settings, emailFields = props.fields.filter( function ( x ) {
			return x.type === 'email';
		} );
		function set( path, value ) {
			var next = clone( s ), parts = path.split( '.' ), o = next;
			while ( parts.length > 1 ) {
				o = o[ parts.shift() ];
			}
			o[ parts[ 0 ] ] = value;
			props.onChange( next );
		}
		return el(
			'div',
			{ className: 'smokyforms-form-settings' },
			el(
				c.PanelBody,
				{ title: __( 'General', 'smokyforms' ), initialOpen: true },
				el( c.TextareaControl, { label: __( 'Form description', 'smokyforms' ), rows: 3, help: sprintf( __( 'Optional. Shown above the form when "Show the form description" is on in the block, or with %s. HTML is allowed.', 'smokyforms' ), '[smokyforms id="' + ( props.formId || 'ID' ) + '" title="yes" description="yes"]' ), value: s.description || '', onChange: function ( v ) {
					set( 'description', v );
				}, __nextHasNoMarginBottom: true } ),
				el( c.TextControl, { label: __( 'Button text', 'smokyforms' ), value: s.submit_label, onChange: function ( v ) {
					set( 'submit_label', v );
				}, __nextHasNoMarginBottom: true } ),
				el( TagInput, { name: 'success', multiline: true, rows: 4, allFields: true, fields: props.fields, label: __( 'Message after sending', 'smokyforms' ), help: __( 'HTML is allowed, for example <strong>, <a href="..."> or <img src="...">.', 'smokyforms' ), value: s.success_message, onChange: function ( v ) {
					set( 'success_message', v );
				} } ),
				el( PagePicker, { label: __( 'Or go to this page after sending', 'smokyforms' ), help: __( 'Leave empty to show the message instead.', 'smokyforms' ), value: s.redirect, onChange: function ( v ) {
					set( 'redirect', v );
				} } ),
				props.fields.some( function ( x ) {
					return x.type === 'page';
				} ) ? el( c.ToggleControl, { label: __( 'Show the progress bar', 'smokyforms' ), help: __( 'For forms with page breaks: "Step 1 of 3" above the form.', 'smokyforms' ), checked: s.progress !== false, onChange: function ( v ) {
					set( 'progress', v );
				}, __nextHasNoMarginBottom: true } ) : null,
				el( c.ToggleControl, {
					label: __( 'CAPTCHA', 'smokyforms' ),
					help: data.captchaReady ? __( 'Protect this form with the CAPTCHA set up under Settings.', 'smokyforms' ) : __( 'No CAPTCHA is set up yet. You can add one under SmokyForms > Settings. The trap field and time check protect every form anyway.', 'smokyforms' ),
					checked: !! s.captcha,
					onChange: function ( v ) {
						set( 'captcha', v );
					},
					__nextHasNoMarginBottom: true,
				} )
			),
			el( ButtonPanel, { settings: s, onChange: function ( button ) {
				set( 'button', button );
			} } ),
			el(
				c.PanelBody,
				{ title: __( 'Notification to you', 'smokyforms' ), initialOpen: true },
				el( 'p', { className: 'description' }, __( 'The free version of SmokyForms emails every entry to you and does not save it. SmokyForms Pro (coming soon) saves all entries in your dashboard, with search and CSV export. If an email fails, the entry is kept under SmokyForms > Undelivered.', 'smokyforms' ) ),
				el( c.ToggleControl, { label: __( 'Send me an email for every entry', 'smokyforms' ), checked: !! s.notify.enabled, onChange: function ( v ) {
					set( 'notify.enabled', v );
				}, __nextHasNoMarginBottom: true } ),
				s.notify.enabled ? el(
					Fragment,
					null,
					el( TagInput, { name: 'notify-to', only: [ 'email' ], separator: ', ', fields: props.fields, label: __( 'Send to', 'smokyforms' ), help: __( 'Separate several addresses with commas.', 'smokyforms' ), value: s.notify.to, onChange: function ( v ) {
						set( 'notify.to', v );
					} } ),
					el( TagInput, { name: 'notify-subject', fields: props.fields, label: __( 'Subject', 'smokyforms' ), value: s.notify.subject, onChange: function ( v ) {
						set( 'notify.subject', v );
					} } ),
					el( TagInput, { name: 'notify-message', multiline: true, rows: 6, allFields: true, fields: props.fields, label: __( 'Message', 'smokyforms' ), help: __( 'HTML is allowed, for example <strong>, <a href="..."> or <img src="...">.', 'smokyforms' ), value: s.notify.message, onChange: function ( v ) {
						set( 'notify.message', v );
					} } )
				) : null
			),
			el(
				c.PanelBody,
				{ title: __( 'Confirmation to the visitor', 'smokyforms' ), initialOpen: true },
				el( c.ToggleControl, { label: __( 'Send the visitor a confirmation email', 'smokyforms' ), checked: !! s.confirm.enabled, onChange: function ( v ) {
					set( 'confirm.enabled', v );
				}, __nextHasNoMarginBottom: true } ),
				s.confirm.enabled ? el(
					Fragment,
					null,
					emailFields.length ? el( c.SelectControl, {
						label: __( 'Address from the field', 'smokyforms' ),
						value: s.confirm.field,
						options: [ { value: '', label: '—' } ].concat( emailFields.map( function ( x ) {
							return { value: x.id, label: x.label || x.id };
						} ) ),
						onChange: function ( v ) {
							set( 'confirm.field', v );
						},
						__nextHasNoMarginBottom: true,
					} ) : el( c.Notice, { status: 'warning', isDismissible: false }, __( 'Add an Email field to the form first.', 'smokyforms' ) ),
					el( TagInput, { name: 'confirm-subject', fields: props.fields, label: __( 'Subject', 'smokyforms' ), value: s.confirm.subject, onChange: function ( v ) {
						set( 'confirm.subject', v );
					} } ),
					el( TagInput, { name: 'confirm-message', multiline: true, rows: 8, allFields: true, fields: props.fields, label: __( 'Message', 'smokyforms' ), help: __( 'HTML is allowed, for example <strong>, <a href="..."> or <img src="...">.', 'smokyforms' ), value: s.confirm.message, onChange: function ( v ) {
						set( 'confirm.message', v );
					} } )
				) : null
			)
		);
	}

	/* Templates */

	function Templates( props ) {
		return el(
			'div',
			{ className: 'smokyforms-templates' },
			el( 'h1', null, __( 'Add New Form', 'smokyforms' ) ),
			el( 'p', null, __( 'Start with a ready-made form or an empty one. Every template, calculators included, is free.', 'smokyforms' ) ),
			el(
				'div',
				{ className: 'smokyforms-template-grid' },
				data.templates.map( function ( t ) {
					return el(
						'button',
						{ key: t.key, type: 'button', className: 'smokyforms-template card', onClick: function () {
							props.onPick( t );
						} },
						el( 'strong', null, t.name ),
						t.calc ? el( 'span', { className: 'smokyforms-badge' }, __( 'Calculation', 'smokyforms' ) ) : null,
						el( 'span', null, t.description )
					);
				} )
			)
		);
	}

	/* App */

	function App() {
		var initial = data.form;
		var view = useState( initial ? 'builder' : 'templates' ),
			id = useState( initial ? initial.id : 0 ),
			title = useState( initial ? initial.title : '' ),
			fields = useState( initial ? initial.fields : [] ),
			tables = useState( initial ? initial.tables : [] ),
			settings = useState( initial ? initial.settings : clone( data.defaults ) ),
			selected = useState( null ),
			tab = useState( 'fields' ),
			dirty = useState( false ),
			saving = useState( false ),
			notice = useState( null );

		useEffect( function () {
			function warn( e ) {
				if ( dirty[ 0 ] ) {
					e.preventDefault();
					e.returnValue = '';
				}
			}
			window.addEventListener( 'beforeunload', warn );
			return function () {
				window.removeEventListener( 'beforeunload', warn );
			};
		}, [ dirty[ 0 ] ] );

		function touch() {
			dirty[ 1 ]( true );
			notice[ 1 ]( null );
		}
		function setFields( list ) {
			fields[ 1 ]( list );
			touch();
		}

		if ( view[ 0 ] === 'templates' ) {
			return el( Templates, { onPick: function ( t ) {
				title[ 1 ]( t.title );
				fields[ 1 ]( clone( t.fields ) );
				tables[ 1 ]( clone( t.tables || [] ) );
				settings[ 1 ]( clone( t.settings ) );
				dirty[ 1 ]( true );
				view[ 1 ]( 'builder' );
			} } );
		}

		var list = fields[ 0 ], current = list.filter( function ( f ) {
			return f.id === selected[ 0 ];
		} )[ 0 ];

		function save() {
			saving[ 1 ]( true );
			notice[ 1 ]( null );
			wp.apiFetch( {
				path: '/smokyforms/v1/forms' + ( id[ 0 ] ? '/' + id[ 0 ] : '' ),
				method: 'POST',
				data: { title: title[ 0 ], form: { fields: list, tables: tables[ 0 ], settings: settings[ 0 ] } },
			} ).then( function ( res ) {
				id[ 1 ]( res.id );
				title[ 1 ]( res.form.title );
				fields[ 1 ]( res.form.fields );
				tables[ 1 ]( res.form.tables );
				settings[ 1 ]( res.form.settings );
				dirty[ 1 ]( false );
				notice[ 1 ]( { status: 'success', text: res.message } );
				if ( ! id[ 0 ] && window.history.replaceState ) {
					window.history.replaceState( null, '', data.builderUrl + '&form=' + res.id );
				}
			} ).catch( function ( err ) {
				notice[ 1 ]( { status: 'error', text: ( err && err.message ) || __( 'The form could not be saved.', 'smokyforms' ) } );
			} ).then( function () {
				saving[ 1 ]( false );
			} );
		}

		var shortcode = id[ 0 ] ? '[smokyforms id="' + id[ 0 ] + '"]' : '';

		var header = el(
			'div',
			{ className: 'smokyforms-header' },
			el( 'a', { href: data.listUrl, className: 'smokyforms-back' }, '← ' + __( 'All Forms', 'smokyforms' ) ),
			el( 'input', { type: 'text', className: 'smokyforms-title', value: title[ 0 ], placeholder: __( 'Form name', 'smokyforms' ), 'aria-label': __( 'Form name', 'smokyforms' ), onChange: function ( e ) {
				title[ 1 ]( e.target.value );
				touch();
			} } ),
			shortcode ? el( 'input', { type: 'text', readOnly: true, className: 'smokyforms-shortcode code', value: shortcode, 'aria-label': __( 'Shortcode', 'smokyforms' ), onFocus: function ( e ) {
				e.target.select();
			} } ) : null,
			el( c.Button, { variant: 'primary', isBusy: saving[ 0 ], disabled: saving[ 0 ], onClick: save }, saving[ 0 ] ? __( 'Saving…', 'smokyforms' ) : __( 'Save form', 'smokyforms' ) )
		);

		var tabs = el(
			'div',
			{ className: 'smokyforms-tabs', role: 'tablist' },
			[ [ 'fields', __( 'Fields', 'smokyforms' ) ], [ 'tables', __( 'Price tables', 'smokyforms' ) ], [ 'settings', __( 'Settings', 'smokyforms' ) ] ].map( function ( t ) {
				return el( 'button', {
					key: t[ 0 ],
					type: 'button',
					role: 'tab',
					'aria-selected': tab[ 0 ] === t[ 0 ],
					className: 'smokyforms-tab' + ( tab[ 0 ] === t[ 0 ] ? ' is-active' : '' ),
					onClick: function () {
						tab[ 1 ]( t[ 0 ] );
					},
				}, t[ 1 ] );
			} )
		);

		var body;
		if ( tab[ 0 ] === 'fields' ) {
			body = el(
				'div',
				{ className: 'smokyforms-builder-body' },
				el( Palette, { onAdd: function ( type ) {
					var f = newField( type, list ), next = list.slice(), at = list.indexOf( current );
					next.splice( at === -1 ? next.length : at + 1, 0, f );
					setFields( next );
					selected[ 1 ]( f.id );
				} } ),
				el( Canvas, {
					fields: list,
					selected: selected[ 0 ],
					onSelect: selected[ 1 ],
					onInsert: function ( type, at ) {
						var f = newField( type, list ), next = list.slice();
						next.splice( at, 0, f );
						setFields( next );
						selected[ 1 ]( f.id );
					},
					onMove: function ( from, to ) {
						var next = list.slice(), item = next.splice( from, 1 )[ 0 ];
						next.splice( to, 0, item );
						setFields( next );
					},
					onDuplicate: function ( i ) {
						var copy = clone( list[ i ] ), next = list.slice();
						copy.id = uid( list, list[ i ].id );
						next.splice( i + 1, 0, copy );
						setFields( next );
						selected[ 1 ]( copy.id );
					},
					onDelete: function ( i ) {
						var next = list.slice();
						next.splice( i, 1 );
						setFields( next );
						selected[ 1 ]( null );
					},
				} ),
				el(
					'div',
					{ className: 'smokyforms-sidebar' },
					el( FieldSettings, {
						field: current,
						fields: list,
						tables: tables[ 0 ],
						onChange: function ( patch ) {
							setFields( list.map( function ( f ) {
								return f.id === current.id ? Object.assign( {}, f, patch ) : f;
							} ) );
						},
						onRename: function ( newId, auto ) {
							if ( ! newId || /^[0-9]/.test( newId ) || newId === current.id || list.some( function ( f ) {
								return f.id === newId;
							} ) ) {
								return;
							}
							var oldId = current.id, re = new RegExp( '\\{' + oldId + '(\\.[a-z0-9_]+)?\\}', 'g' ), s = clone( settings[ 0 ] );
							fields[ 1 ]( function ( prev ) {
								return prev.map( function ( f ) {
									var copy = Object.assign( {}, f );
									if ( f.id === oldId ) {
										copy.id = newId;
										copy._autoId = !! auto;
									}
									if ( copy.formula ) {
										copy.formula = copy.formula.replace( re, '{' + newId + '$1}' );
									}
									if ( copy.logic ) {
										copy.logic = clone( copy.logic );
										copy.logic.rules.forEach( function ( r ) {
											if ( r.field === oldId ) {
												r.field = newId;
											}
										} );
									}
									return copy;
								} );
							} );
							touch();
							if ( s.confirm.field === oldId ) {
								s.confirm.field = newId;
								settings[ 1 ]( s );
							}
							selected[ 1 ]( newId );
						},
					} )
				)
			);
		} else if ( tab[ 0 ] === 'tables' ) {
			body = el( TablesTab, { tables: tables[ 0 ], fields: list, onChange: function ( t ) {
				tables[ 1 ]( t );
				touch();
			} } );
		} else {
			body = el( SettingsTab, { settings: settings[ 0 ], fields: list, formId: id[ 0 ], onChange: function ( s ) {
				settings[ 1 ]( s );
				touch();
			} } );
		}

		return el(
			'div',
			{ className: 'smokyforms-builder' },
			header,
			notice[ 0 ] ? el( c.Notice, { status: notice[ 0 ].status, onRemove: function () {
				notice[ 1 ]( null );
			} }, notice[ 0 ].text, notice[ 0 ].status === 'success' && shortcode ? el( 'span', null, ' ', __( 'Add it to a page with the SmokyForms block or the shortcode.', 'smokyforms' ) ) : null ) : null,
			dirty[ 0 ] ? el( 'p', { className: 'smokyforms-unsaved' }, __( 'You have unsaved changes.', 'smokyforms' ) ) : null,
			tabs,
			body
		);
	}

	wp.domReady = wp.domReady || function ( cb ) {
		if ( document.readyState === 'loading' ) {
			document.addEventListener( 'DOMContentLoaded', cb );
		} else {
			cb();
		}
	};
	wp.domReady( function () {
		var root = document.getElementById( 'smokyforms-builder' );
		if ( ! root ) {
			return;
		}
		if ( wp.element.createRoot ) {
			wp.element.createRoot( root ).render( el( App ) );
		} else {
			wp.element.render( el( App ), root );
		}
	} );
}( window.wp, window.smokyformsBuilder ) );
