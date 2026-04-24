/**
 * React hook that is used to mark the block wrapper element.
 * It provides all the necessary props like the class name.
 *
 * @see https://developer.wordpress.org/block-editor/reference-guides/packages/packages-block-editor/#useblockprops
 */
import {useBlockProps, InnerBlocks} from '@wordpress/block-editor';

/**
 * The save function defines the way in which the different attributes should
 * be combined into the final markup, which is then serialized by the block
 * editor into `post_content`.
 *
 * @see https://developer.wordpress.org/block-editor/reference-guides/block-api/block-edit-save/#save
 *
 * @return {Element} Element to render.
 */
export default function save({attributes}) {

    const classes = [
        attributes.buttonAndBorderColor ? 'has-button-and-input-color' : '',
    ].filter(Boolean).join(' ');

    const styles = {
        '--button-and-input-color': attributes.buttonAndBorderColor,
        borderRadius: attributes.borderRadius || '0px',
    }

    const blockProps = useBlockProps.save({
        className: classes,
        style: {
            ...styles
        }
    })

    return (
        <div {...blockProps}>
            <form
                data-success-message={attributes.success_message}
                data-error-message={attributes.error_message}
                data-double-optin={attributes.double_optin ?? true}
                className="mailerpress-optin-form"
            >
                <InnerBlocks.Content/>
                <input type="hidden" name={"mailerpress-list"} value={attributes.list}/>
                <input type="hidden" name={"mailerpress-tags"} value={JSON.stringify(attributes.tags)}/>
                {/* Honeypot field - hidden from humans, bots will fill it */}
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
}
