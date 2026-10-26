import {useBlockProps, InnerBlocks} from '@wordpress/block-editor';
import metadata from './block.json';

// Snapshot of forms that saved the former subscription-groups hidden field.
// Keep this serializer independent of save.js so future markup changes stay compatible.
const subscriptionGroups = {
    attributes: {
        ...metadata.attributes,
        subscription_categories: {
            type: 'array',
            default: [],
        },
    },
    supports: metadata.supports,
    save({attributes}) {
        const blockProps = useBlockProps.save({
            className: attributes.buttonAndBorderColor ? 'has-button-and-input-color' : '',
            style: {
                '--button-and-input-color': attributes.buttonAndBorderColor,
                borderRadius: attributes.borderRadius || '0px',
            },
        });

        return (
            <div {...blockProps}>
                <form
                    data-success-message={attributes.success_message}
                    data-error-message={attributes.error_message}
                    data-double-optin={attributes.double_optin ?? true}
                    data-redirect-url={attributes.redirect_url || undefined}
                    className="mailerpress-optin-form"
                >
                    <InnerBlocks.Content/>
                    <input type="hidden" name="mailerpress-list" value={attributes.list}/>
                    <input type="hidden" name="mailerpress-tags" value={JSON.stringify(attributes.tags)}/>
                    <input type="hidden" name="mailerpress-subscription-groups" value={JSON.stringify(attributes.subscription_categories)}/>
                    <input
                        type="text"
                        name="website"
                        value=""
                        tabIndex="-1"
                        autoComplete="off"
                        style={{position: 'absolute', left: '-9999px', width: '1px', height: '1px'}}
                        aria-hidden="true"
                    />
                </form>
            </div>
        );
    },
    migrate({subscription_categories, ...attributes}, innerBlocks) {
        return [attributes, innerBlocks];
    },
};

export default [subscriptionGroups];
