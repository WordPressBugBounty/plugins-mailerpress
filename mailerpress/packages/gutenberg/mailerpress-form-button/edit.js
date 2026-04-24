import { __ } from "@wordpress/i18n";
import {
  useBlockProps,
  RichText,
  InspectorControls,
  __experimentalBorderRadiusControl as BorderRadiusControl,
} from "@wordpress/block-editor";
import { RangeControl, PanelBody } from "@wordpress/components";
import "./editor.scss";

export default function Edit({ attributes, setAttributes }) {
  const { content } = attributes;

  const radius = attributes.borderRadius || "0px";
  const borderRadiusValue =
    typeof radius === "object"
      ? `${radius.topLeft || "0px"} ${radius.topRight || "0px"} ${radius.bottomRight || "0px"} ${radius.bottomLeft || "0px"}`
      : radius;

  const blockProps = useBlockProps({
    style: {
      color: attributes.color || "#fff",
      padding: attributes.padding || "8px",
      borderRadius: borderRadiusValue,
      fontSize: attributes.fontSize || "small",
      width: attributes.buttonWidth > 0 ? `${attributes.buttonWidth}%` : "auto",
    },
  });

  const handleKeyDown = (event) => {
    if (event.key === "Enter") {
      event.preventDefault(); // Disable new line behavior
    }
  };

  return (
    <div className="mailerpress-optin-form__submit">
      <InspectorControls>
        <PanelBody title={__("Dimensions", "mailerpress")}>
          <BorderRadiusControl
            values={attributes.borderRadius}
            onChange={(value) => {
              setAttributes({ borderRadius: value });
            }}
          />
          <RangeControl
            label={__("Width", "mailerpress")}
            value={attributes.buttonWidth}
            max={100}
            min={0}
            onChange={(value) =>
              setAttributes({
                buttonWidth: value,
              })
            }
          />
        </PanelBody>
      </InspectorControls>
      <button type="submit" {...blockProps}>
        <RichText
          tagName="span"
          value={content || __("Submit", "mailerpress")}
          allowedFormats={["core/bold", "core/italic"]}
          onChange={(content) => setAttributes({ content })}
          placeholder={__("Submit...", "mailerpress")}
          onKeyDown={handleKeyDown}
        />
      </button>
    </div>
  );
}
