import { useBlockProps, RichText } from "@wordpress/block-editor";
import { __ } from "@wordpress/i18n";

export default function save({ attributes }) {
  const radius = attributes.borderRadius || "0px";
  const borderRadiusValue =
    typeof radius === "object"
      ? `${radius.topLeft || "0px"} ${radius.topRight || "0px"} ${radius.bottomRight || "0px"} ${radius.bottomLeft || "0px"}`
      : radius;

  const blockProps = useBlockProps.save({
    style: {
      color: attributes.color || "#fff",
      padding: attributes.padding || "8px",
      borderRadius: borderRadiusValue,
      fontSize: attributes.fontSize || "small",
      width: attributes.buttonWidth > 0 ? `${attributes.buttonWidth}%` : "auto",
    },
  });

  return (
    <div className="mailerpress-optin-form__submit">
      <button type="submit" {...blockProps}>
        <RichText.Content
          tagName="span"
          value={attributes.content || __("Submit", "mailerpress")}
        />
      </button>
    </div>
  );
}
