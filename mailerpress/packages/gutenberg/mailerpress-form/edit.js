/**
 * Retrieves the translation of text.
 *
 * @see https://developer.wordpress.org/block-editor/reference-guides/packages/packages-i18n/
 */
import {__} from '@wordpress/i18n';
import {useState, useEffect} from '@wordpress/element';
import apiFetch from '@wordpress/api-fetch';
/**
 * React hook that is used to mark the block wrapper element.
 * It provides all the necessary props like the class name.
 *
 * @see https://developer.wordpress.org/block-editor/reference-guides/packages/packages-block-editor/#useblockprops
 */
import {
    useBlockProps,
    InnerBlocks,
    InspectorControls,
    __experimentalColorGradientSettingsDropdown as ColorGradientSettingsDropdown,
    __experimentalUseMultipleOriginColorsAndGradients as useMultipleOriginColorsAndGradients,
    __experimentalBorderRadiusControl as BorderRadiusControl
} from '@wordpress/block-editor';
import {
    PanelBody,
    SelectControl,
    FormTokenField,
    TextControl,
    ToggleControl,
} from '@wordpress/components';

/**
 * Lets webpack process CSS, SASS or SCSS files referenced in JavaScript files.
 * Those files can contain any CSS code that gets applied to the editor.
 *
 * @see https://www.npmjs.com/package/@wordpress/scripts#using-css
 */
import './editor.scss';

export function classnames(...rest) {
    return rest.filter(item => typeof item === 'string').join(' ');
}

/**
 * The edit function describes the structure of your block in the context of the
 * editor. This represents what the editor will render when the block is used.
 *
 * @see https://developer.wordpress.org/block-editor/reference-guides/block-api/block-edit-save/#edit
 *
 * @return {Element} Element to render.
 */
export default function Edit({attributes, setAttributes, clientId}) {
    const {tags = [], list} = attributes;

    const [lists, setLists] = useState([]);
    const [availableTags, setAvailableTags] = useState([]);
    const [tagsLoading, setTagsLoading] = useState(true);
    const [loading, setLoading] = useState(true);

    useEffect(() => {
        setLoading(true);
        setTagsLoading(true);

        Promise.all([
            apiFetch({path: '/mailerpress/v1/list/all'}),
            apiFetch({path: '/mailerpress/v1/tag/all'})
        ])
            .then(([listsData, tagsData]) => {
                const formattedLists = listsData.map((list) => ({
                    label: list.name,
                    value: list.id,
                }));
                setLists(formattedLists);

                const formattedTags = tagsData.map((tag) => ({
                    label: tag.name,
                    value: tag.id,
                }));
                setAvailableTags(formattedTags);
            })
            .catch((error) => {
            })
            .finally(() => {
                setLoading(false);
                setTagsLoading(false)
            });
    }, []);


    const classes = [
        attributes.buttonAndBorderColor ? 'has-button-and-input-color' : '',
    ].filter(Boolean).join(' ');

    const styles = {
        '--button-and-input-color': attributes.buttonAndBorderColor,
        borderRadius: attributes.borderRadius || '0px',
    }

    const blockProps = useBlockProps({
        className: classes,
        style: {...styles}
    })

    const TEMPLATE = [
        ['mailerpress/form-input', {label: __('Last name', 'mailerpress'), type: 'contactLastName'}],
        ['core/spacer', {height: '16px'}],
        ['mailerpress/form-input', {label: __('First name', 'mailerpress'), type: 'contactFirstName'}],
        ['core/spacer', {height: '16px'}],
        ['mailerpress/form-input', {label: __('Email', 'mailerpress'), type: 'contactEmail'}],
        ['core/spacer', {height: '16px'}],
        ['mailerpress/form-button', {label: __('Email', 'mailerpress'), type: 'email'}],
    ];

    const colorGradientSettings = useMultipleOriginColorsAndGradients()

    return (
        <>
            <InspectorControls>
                <PanelBody title={__('Form Settings', 'mailerpress')}>
                    <SelectControl
                        __next40pxDefaultSize
                        __nextHasNoMarginBottom
                        label={__('List', 'mailerpress')}
                        help={__('Choose the list where the lead will be registered', 'mailerpress')}
                        value={list}
                        onChange={(newValue) => setAttributes({list: newValue})}
                        options={[
                            {
                                label: loading ? __('Loading...', 'mailerpress') : __('Select a list', 'mailerpress'),
                                value: ''
                            },
                            ...lists
                        ]}
                    />
                    <FormTokenField
                        __experimentalExpandOnFocus
                        __next40pxDefaultSize
                        label={__('Tags', 'mailerpress')}
                        value={tags.map((tagId) => {
                            const match = availableTags.find(t => t.value === tagId);
                            return match ? match.label : tagId;
                        })}
                        suggestions={availableTags.map(tag => tag.label)}
                        onChange={(selectedLabels) => {
                            const selectedTagIds = selectedLabels
                                .map(label => availableTags.find(tag => tag.label === label)?.value)
                                .filter(Boolean);
                            setAttributes({tags: selectedTagIds});
                        }}
                    />
                    <ToggleControl
                        __nextHasNoMarginBottom
                        label={__('Enable Double Opt-in', 'mailerpress')}
                        help={__('When enabled, users will receive a confirmation email and must confirm their subscription before being added to your list.', 'mailerpress')}
                        checked={attributes.double_optin ?? true}
                        onChange={(value) => setAttributes({double_optin: value})}
                    />
                    <TextControl
                        help={__('This message will be displayed when a user successfully subscribes.', 'mailerpress')}
                        label={__('Success message', 'mailerpress')}
                        __next40pxDefaultSize
                        onChange={(value) => setAttributes({success_message: value})}
                        value={attributes.success_message}
                    />
                    <TextControl
                        help={__('This message will be shown when the subscription process fails (e.g., invalid email or server error).', 'mailerpress')}
                        label={__('Error message', 'mailerpress')}
                        __next40pxDefaultSize
                        onChange={(value) => setAttributes({error_message: value})}
                        value={attributes.error_message}
                    />
                </PanelBody>
                <PanelBody title={__('Dimensions', 'mailerpress')}>
                    <BorderRadiusControl
                        values={attributes.borderRadius}
                        onChange={(value) => {
                            setAttributes({borderRadius: `${value}`})
                        }}
                    />
                </PanelBody>
            </InspectorControls>
            <InspectorControls group={"color"}>
                <ColorGradientSettingsDropdown
                    panelId={clientId}
                    settings={[
                        {
                            label: __('Button and input border', 'mailerpress'),
                            colorValue: attributes.buttonAndBorderColor,
                            onColorChange: (color) => setAttributes({buttonAndBorderColor: color}),
                        }
                    ]}
                    {...colorGradientSettings}
                />
            </InspectorControls>
            <div {...blockProps} >
                <InnerBlocks
                    template={TEMPLATE}
                    templateLock={false}
                />
            </div>
        </>
    );
}
