import { useBlockProps } from "@wordpress/block-editor";

export default function save({ attributes }) {
  const {
    label = "",
    type = "text",
    required,
    borderRadius,
    fontSize,
    placeholder = "",
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
      />
    </div>
  );
}
