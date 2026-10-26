import { useBlockProps } from "@wordpress/block-editor";

/**
 * Inline padding for the input, or undefined when unset so existing blocks keep their markup.
 */
export function getInputPaddingStyle(inputPadding) {
  if (!inputPadding) {
    return undefined;
  }

  const style = {
    paddingTop: inputPadding.top,
    paddingRight: inputPadding.right,
    paddingBottom: inputPadding.bottom,
    paddingLeft: inputPadding.left,
  };
  Object.keys(style).forEach((key) => !style[key] && delete style[key]);

  return Object.keys(style).length ? style : undefined;
}

export default function save({ attributes }) {
  const {
    label = "",
    type = "text",
    required,
    borderRadius,
    fontSize,
    placeholder = "",
    inputPadding,
  } = attributes;

  const inputId = `input-${label.replace(/\s+/g, "-").toLowerCase()}`;

  const classes = [
    "mailerpress-optin-form__field",
    borderRadius ? "has-input-radius" : "",
  ]
    .filter(Boolean)
    .join(" ");

  const borderRadiusValue =
    typeof borderRadius === "object"
      ? `${borderRadius.topLeft || "0px"} ${borderRadius.topRight || "0px"} ${borderRadius.bottomRight || "0px"} ${borderRadius.bottomLeft || "0px"}`
      : borderRadius;

  const styles = {
    "--input-radius": borderRadiusValue,
    fontSize: fontSize || "small",
  };

  const blockProps = useBlockProps.save({
    className: classes,
    style: styles,
  });

  return (
    <div {...blockProps}>
      {label && (
        <label htmlFor={inputId}>
          {label} {required && "*"}
        </label>
      )}
      <input
        required={required}
        id={inputId}
        name={type}
        placeholder={placeholder}
        aria-label={label}
        style={getInputPaddingStyle(inputPadding)}
      />
    </div>
  );
}
