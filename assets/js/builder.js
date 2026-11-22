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
		f._autoId = true;
		return f;
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
			m = /^\{([a-z0-9_]+)\}/.exec( s.slice( i ) );
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
							return x ? ( x.label || x.id ) : '?';
						}
						return t.k === 'op' && OP_LABELS[ t.t ] ? OP_LABELS[ t.t ] : t.t;
					} ).join( ' ' ).replace( /\( /g, '(' ).replace( / \)/g, ')' ).replace( / ,/g, ',' ) : __( 'No formula yet', 'smokyforms' ) )
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
			el( 'span', { className: 'smokyforms-preview-type' }, t.label ),
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
			others = props.fields.filter( function ( x ) {
				return x.id !== f.id && TYPES[ x.type ] && TYPES[ x.type ].numeric;
			} ),
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
				if ( ! x ) {
					return 0;
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
				label = byId[ t.t ] ? ( byId[ t.t ].label || t.t ) : sprintf( __( 'Missing field: %s', 'smokyforms' ), t.t );
				if ( ! byId[ t.t ] ) {
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
						return el( c.Button, { key: x.id, variant: 'secondary', size: 'compact', className: 'smokyforms-pad-field', onClick: function () {
							add( '{' + x.id + '}' );
						} }, x.label || x.id );
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
					if ( ! x || x.type === 'calculation' ) {
						return null;
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

		controls.push( el( c.TextControl, { key: 'label', label: f.type === 'heading' ? __( 'Heading', 'smokyforms' ) : __( 'Label', 'smokyforms' ), value: f.label, onChange: function ( v ) {
			set( { label: v } );
			// A new field takes its ID from the label until the ID is changed by hand or the form is saved.
			if ( f._autoId && slug( v ) ) {
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
		if ( f.type !== 'hidden' && f.type !== 'heading' && f.type !== 'html' ) {
			controls.push( el( c.TextControl, { key: 'help', label: __( 'Help text', 'smokyforms' ), value: f.help, onChange: function ( v ) {
				set( { help: v } );
			}, __nextHasNoMarginBottom: true } ) );
		}
		if ( f.type !== 'hidden' && f.type !== 'heading' && f.type !== 'html' ) {
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
		var tagHelp = el(
			'p',
			{ className: 'description smokyforms-tags' },
			__( 'You can use:', 'smokyforms' ),
			' ',
			Object.keys( data.tags ).map( function ( tag ) {
				return el( 'code', { key: tag, title: data.tags[ tag ] }, tag );
			} ),
			' ',
			el( 'code', { title: __( 'Answer of one field', 'smokyforms' ) }, '{field:ID}' )
		);
		return el(
			'div',
			{ className: 'smokyforms-form-settings' },
			el(
				c.PanelBody,
				{ title: __( 'General', 'smokyforms' ), initialOpen: true },
				el( c.TextControl, { label: __( 'Button text', 'smokyforms' ), value: s.submit_label, onChange: function ( v ) {
					set( 'submit_label', v );
				}, __nextHasNoMarginBottom: true } ),
				el( c.TextareaControl, { label: __( 'Message after sending', 'smokyforms' ), value: s.success_message, onChange: function ( v ) {
					set( 'success_message', v );
				}, __nextHasNoMarginBottom: true } ),
				el( c.TextControl, { label: __( 'Or go to this page after sending', 'smokyforms' ), help: __( 'Leave empty to show the message instead.', 'smokyforms' ), type: 'url', value: s.redirect, onChange: function ( v ) {
					set( 'redirect', v );
				}, __nextHasNoMarginBottom: true } ),
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
			el(
				c.PanelBody,
				{ title: __( 'Notification to you', 'smokyforms' ), initialOpen: true },
				el( 'p', { className: 'description' }, __( 'SmokyForms does not store entries; every entry is emailed to you. If the email fails, the entry is kept under SmokyForms > Undelivered.', 'smokyforms' ) ),
				el( c.ToggleControl, { label: __( 'Send me an email for every entry', 'smokyforms' ), checked: !! s.notify.enabled, onChange: function ( v ) {
					set( 'notify.enabled', v );
				}, __nextHasNoMarginBottom: true } ),
				s.notify.enabled ? el(
					Fragment,
					null,
					el( c.TextControl, { label: __( 'Send to', 'smokyforms' ), help: __( 'Separate several addresses with commas.', 'smokyforms' ), value: s.notify.to, onChange: function ( v ) {
						set( 'notify.to', v );
					}, __nextHasNoMarginBottom: true } ),
					el( c.TextControl, { label: __( 'Subject', 'smokyforms' ), value: s.notify.subject, onChange: function ( v ) {
						set( 'notify.subject', v );
					}, __nextHasNoMarginBottom: true } ),
					el( c.TextareaControl, { label: __( 'Message', 'smokyforms' ), rows: 5, value: s.notify.message, onChange: function ( v ) {
						set( 'notify.message', v );
					}, __nextHasNoMarginBottom: true } ),
					tagHelp
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
					el( c.TextControl, { label: __( 'Subject', 'smokyforms' ), value: s.confirm.subject, onChange: function ( v ) {
						set( 'confirm.subject', v );
					}, __nextHasNoMarginBottom: true } ),
					el( c.TextareaControl, { label: __( 'Message', 'smokyforms' ), rows: 6, value: s.confirm.message, onChange: function ( v ) {
						set( 'confirm.message', v );
					}, __nextHasNoMarginBottom: true } ),
					tagHelp
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
							var oldId = current.id, re = new RegExp( '\\{' + oldId + '\\}', 'g' ), s = clone( settings[ 0 ] );
							fields[ 1 ]( function ( prev ) {
								return prev.map( function ( f ) {
									var copy = Object.assign( {}, f );
									if ( f.id === oldId ) {
										copy.id = newId;
										copy._autoId = !! auto;
									}
									if ( copy.formula ) {
										copy.formula = copy.formula.replace( re, '{' + newId + '}' );
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
			body = el( SettingsTab, { settings: settings[ 0 ], fields: list, onChange: function ( s ) {
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
