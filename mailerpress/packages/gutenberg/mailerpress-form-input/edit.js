import { __ } from "@wordpress/i18n";
import {
  useBlockProps,
  InspectorControls,
  __experimentalBorderRadiusControl as BorderRadiusControl,
  useSetting,
} from "@wordpress/block-editor";
import {
  TextControl,
  PanelBody,
  SelectControl,
  CheckboxControl,
  BoxControl,
} from "@wordpress/components";
import "./editor.scss";

export default function Edit({ attributes, setAttributes }) {
  const {
    label = "",
    type = "text",
    required,
    borderRadius,
    placeholder = "",
  } = attributes; // Fallbacks for missing data

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
    fontSize: attributes.fontSize || "small",
  };

  const blockProps = useBlockProps({
    className: classes,
    style: styles,
  });

  const inputId = `input-${label.replace(/\s+/g, "-").toLowerCase()}`;

  return (
    <>
      <InspectorControls>
        <PanelBody title={__("Field Settings", "mailerpress")}>
          <TextControl
            label={__("Label", "mailerpress")}
            value={label}
            onChange={(newLabel) => setAttributes({ label: newLabel })}
          />
          <SelectControl
            __next40pxDefaultSize
            __nextHasNoMarginBottom
            label={__("Type", "mailerpress")}
            value={type}
            onChange={(newType) => setAttributes({ type: newType })}
            options={[
              {
                label: __("First Name", "mailerpress"),
                value: "contactFirstName",
              },
              {
                label: __("Last Name", "mailerpress"),
                value: "contactLastName",
              },
              { label: __("Email", "mailerpress"), value: "contactEmail" },
            ]}
          />
          <TextControl
            label={__("Placeholder", "mailerpress")}
            value={placeholder}
            onChange={(newPlaceholder) =>
              setAttributes({ placeholder: newPlaceholder })
            }
            placeholder={__("Enter custom placeholder", "mailerpress")}
          />
          <CheckboxControl
            __nextHasNoMarginBottom
            checked={required}
            label={__("Required field", "mailerpress")}
            onChange={(val) => setAttributes({ required: val })}
          />
        </PanelBody>
        <InspectorControls>
          <PanelBody title={__("Dimensions", "mailerpress")}>
            <BoxControl
              __next40pxDefaultSize
              label={__("Input padding", "mailerpress")}
              onChange={() => {}}
            />
            <BorderRadiusControl
              values={borderRadius}
              onChange={(value) => {
                setAttributes({ borderRadius: value });
              }}
            />
          </PanelBody>
        </InspectorControls>
      </InspectorControls>

      <div {...blockProps}>
        {label && (
          <label htmlFor={inputId}>
            {label} {required && "*"}
          </label>
        )}
        <input
          required={required}
          id={inputId}
          name={label.toLowerCase()}
          placeholder={placeholder}
          aria-label={label}
        />
      </div>
    </>
  );
}
