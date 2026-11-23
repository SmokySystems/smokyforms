/**
 * SmokyForms block: pick a form, preview it in the editor.
 */
( function ( wp ) {
	'use strict';

	var el = wp.element.createElement,
		useState = wp.element.useState,
		useEffect = wp.element.useEffect,
		__ = wp.i18n.__,
		be = wp.blockEditor,
		c = wp.components,
		ServerSideRender = wp.serverSideRender;

	function Edit( props ) {
		var formId = props.attributes.formId,
			blockProps = be.useBlockProps(),
			state = useState( null ),
			forms = state[ 0 ],
			setForms = state[ 1 ];

		useEffect( function () {
			wp.apiFetch( { path: '/smokyforms/v1/forms' } ).then( setForms ).catch( function () {
				setForms( [] );
			} );
		}, [] );

		var options = [ { value: 0, label: __( 'Choose a form', 'smokyforms' ) } ].concat(
			( forms || [] ).map( function ( f ) {
				return { value: f.id, label: f.title };
			} )
		);

		var picker = el( c.SelectControl, {
			label: __( 'Form', 'smokyforms' ),
			value: formId,
			options: options,
			onChange: function ( v ) {
				props.setAttributes( { formId: parseInt( v, 10 ) || 0 } );
			},
			__nextHasNoMarginBottom: true,
			__next40pxDefaultSize: true,
		} );

		var inspector = el(
			be.InspectorControls,
			null,
			el(
				c.PanelBody,
				{ title: __( 'Form', 'smokyforms' ) },
				picker,
				formId ? el( c.ToggleControl, { label: __( 'Show the form title', 'smokyforms' ), checked: !! props.attributes.showTitle, onChange: function ( v ) {
					props.setAttributes( { showTitle: v } );
				}, __nextHasNoMarginBottom: true } ) : null,
				formId ? el( c.ToggleControl, { label: __( 'Show the form description', 'smokyforms' ), help: __( 'The description is written in the form settings.', 'smokyforms' ), checked: !! props.attributes.showDescription, onChange: function ( v ) {
					props.setAttributes( { showDescription: v } );
				}, __nextHasNoMarginBottom: true } ) : null
			)
		);

		if ( ! formId ) {
			return el(
				'div',
				blockProps,
				inspector,
				el(
					c.Placeholder,
					{ icon: 'feedback', label: 'SmokyForms', instructions: forms && ! forms.length ? __( 'You have no forms yet. Create one under SmokyForms in the admin menu.', 'smokyforms' ) : __( 'Choose the form to show here.', 'smokyforms' ) },
					forms === null ? el( c.Spinner ) : ( forms.length ? picker : null )
				)
			);
		}
		return el(
			'div',
			blockProps,
			inspector,
			el( c.Disabled, null, el( ServerSideRender, { block: 'smokyforms/form', attributes: { formId: formId, showTitle: !! props.attributes.showTitle, showDescription: !! props.attributes.showDescription } } ) )
		);
	}

	wp.blocks.registerBlockType( 'smokyforms/form', {
		edit: Edit,
		save: function () {
			return null;
		},
	} );
}( window.wp ) );
