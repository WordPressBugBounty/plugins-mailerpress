/**
 * WordPress dependencies
 */
import { __ } from "@wordpress/i18n";
import { useState, useEffect } from "@wordpress/element";
import apiFetch from "@wordpress/api-fetch";
import { useBlockProps, InspectorControls } from "@wordpress/block-editor";
import {
  PanelBody,
  SelectControl,
  RangeControl,
  ToggleControl,
  Spinner,
  ColorPicker,
  __experimentalUnitControl as UnitControl,
} from "@wordpress/components";

/**
 * Edit component for Campaign Archive block
 */
export default function Edit({ attributes, setAttributes }) {
  const {
    year,
    limit,
    order,
    showDate,
    datePosition,
    showSeparator,
    separatorWidth,
    separatorColor,
    itemSpacing,
    dateColor,
  } = attributes;

  const [campaigns, setCampaigns] = useState([]);
  const [loading, setLoading] = useState(true);
  const [years, setYears] = useState([]);

  const blockProps = useBlockProps();

  // Fetch campaigns for preview
  useEffect(() => {
    setLoading(true);
    apiFetch({ path: "/mailerpress/v1/campaigns/sent" })
      .then((data) => {
        setCampaigns(data || []);
        const uniqueYears = [
          ...new Set(
            (data || []).map((c) => new Date(c.updated_at).getFullYear()),
          ),
        ].sort((a, b) => b - a);
        setYears(uniqueYears);
      })
      .catch(() => {
        setCampaigns([]);
      })
      .finally(() => {
        setLoading(false);
      });
  }, []);

  // Filter and sort campaigns based on attributes
  const filteredCampaigns = campaigns
    .filter((c) => {
      if (!year) return true;
      const campaignYear = new Date(c.updated_at).getFullYear();
      return campaignYear === parseInt(year);
    })
    .sort((a, b) => {
      const dateA = new Date(a.updated_at);
      const dateB = new Date(b.updated_at);
      return order === "DESC" ? dateB - dateA : dateA - dateB;
    })
    .slice(0, limit > 0 ? limit : undefined);

  const formatDate = (dateString) => {
    const date = new Date(dateString);
    return date.toLocaleDateString("fr-FR", {
      day: "numeric",
      month: "long",
      year: "numeric",
    });
  };

  const getItemStyle = (isLast) => ({
    display: "flex",
    flexDirection: datePosition === "left" ? "row-reverse" : "row",
    justifyContent: "space-between",
    alignItems: "center",
    padding: `${itemSpacing}px 0`,
    borderBottom:
      showSeparator && !isLast
        ? `${separatorWidth}px solid ${separatorColor}`
        : "none",
  });

  const dateStyle = {
    color: dateColor,
    fontSize: "0.9em",
  };

  return (
    <>
      <InspectorControls>
        <PanelBody title={__("Archive Settings", "mailerpress")}>
          <SelectControl
            label={__("Filter by year", "mailerpress")}
            value={year}
            onChange={(newValue) => setAttributes({ year: newValue })}
            options={[
              { label: __("All years", "mailerpress"), value: "" },
              ...years.map((y) => ({ label: String(y), value: String(y) })),
            ]}
          />
          <RangeControl
            label={__("Number of campaigns", "mailerpress")}
            help={__("Set to -1 to show all campaigns", "mailerpress")}
            value={limit}
            onChange={(newValue) => setAttributes({ limit: newValue })}
            min={-1}
            max={50}
          />
          <SelectControl
            label={__("Order", "mailerpress")}
            value={order}
            onChange={(newValue) => setAttributes({ order: newValue })}
            options={[
              { label: __("Newest first", "mailerpress"), value: "DESC" },
              { label: __("Oldest first", "mailerpress"), value: "ASC" },
            ]}
          />
        </PanelBody>

        <PanelBody
          title={__("Date Settings", "mailerpress")}
          initialOpen={false}
        >
          <ToggleControl
            label={__("Show date", "mailerpress")}
            checked={showDate}
            onChange={(newValue) => setAttributes({ showDate: newValue })}
          />
          {showDate && (
            <>
              <SelectControl
                label={__("Date position", "mailerpress")}
                value={datePosition}
                onChange={(newValue) =>
                  setAttributes({ datePosition: newValue })
                }
                options={[
                  { label: __("Right", "mailerpress"), value: "right" },
                  { label: __("Left", "mailerpress"), value: "left" },
                ]}
              />
              <p style={{ marginBottom: "8px" }}>
                {__("Date color", "mailerpress")}
              </p>
              <ColorPicker
                color={dateColor}
                onChange={(newValue) => setAttributes({ dateColor: newValue })}
                enableAlpha
              />
            </>
          )}
        </PanelBody>

        <PanelBody
          title={__("Separator Settings", "mailerpress")}
          initialOpen={false}
        >
          <ToggleControl
            label={__("Show separator", "mailerpress")}
            checked={showSeparator}
            onChange={(newValue) => setAttributes({ showSeparator: newValue })}
          />
          {showSeparator && (
            <>
              <RangeControl
                label={__("Separator thickness", "mailerpress")}
                value={separatorWidth}
                onChange={(newValue) =>
                  setAttributes({ separatorWidth: newValue })
                }
                min={1}
                max={10}
              />
              <p style={{ marginBottom: "8px" }}>
                {__("Separator color", "mailerpress")}
              </p>
              <ColorPicker
                color={separatorColor}
                onChange={(newValue) =>
                  setAttributes({ separatorColor: newValue })
                }
                enableAlpha
              />
            </>
          )}
        </PanelBody>

        <PanelBody title={__("Spacing", "mailerpress")} initialOpen={false}>
          <RangeControl
            label={__("Item spacing (px)", "mailerpress")}
            value={itemSpacing}
            onChange={(newValue) => setAttributes({ itemSpacing: newValue })}
            min={0}
            max={40}
          />
        </PanelBody>
      </InspectorControls>

      <div {...blockProps}>
        {loading ? (
          <div style={{ textAlign: "center", padding: "20px" }}>
            <Spinner />
          </div>
        ) : filteredCampaigns.length === 0 ? (
          <p>{__("No sent campaigns found.", "mailerpress")}</p>
        ) : (
          <ul style={{ listStyle: "none", margin: 0, padding: 0 }}>
            {filteredCampaigns.map((campaign, index) => (
              <li
                key={campaign.campaign_id}
                style={getItemStyle(index === filteredCampaigns.length - 1)}
              >
                <span>{campaign.name}</span>
                {showDate && (
                  <span style={dateStyle}>
                    {formatDate(campaign.updated_at)}
                  </span>
                )}
              </li>
            ))}
          </ul>
        )}
      </div>
    </>
  );
}
