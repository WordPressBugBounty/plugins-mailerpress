const { createHigherOrderComponent } = wp.compose;
const { InspectorControls } = wp.blockEditor;
const { PanelBody } = wp.components;

const withMyPluginControls = createHigherOrderComponent( ( BlockEdit ) => {
    return ( props ) => {
        return (
            <>
                <BlockEdit key="edit" { ...props } />
                <InspectorControls>
                    <PanelBody>My custom control</PanelBody>
                </InspectorControls>
            </>
        );
    };
}, 'withMyPluginControls' );

wp.hooks.addFilter(
    'editor.BlockEdit',
    'my-plugin/with-inspector-controls',
    withMyPluginControls
);