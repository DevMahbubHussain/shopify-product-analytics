import type { CSSProperties } from "react";

const SHIMMER_STYLE = `
@keyframes spaShimmer {
  0% { background-position: -200% 0; }
  100% { background-position: 200% 0; }
}
.spa-shimmer {
  background: linear-gradient(90deg, #f1f2f3 25%, #e1e3e5 50%, #f1f2f3 75%);
  background-size: 200% 100%;
  animation: spaShimmer 1.5s infinite ease-in-out;
}
`;

export function ShimmerStyle() {
  return <style dangerouslySetInnerHTML={{ __html: SHIMMER_STYLE }} />;
}

export function ShimmerBlock({
  width = "100%",
  height = "16px",
  borderRadius = "4px",
  style,
}: {
  width?: string | number;
  height?: string | number;
  borderRadius?: string | number;
  style?: CSSProperties;
}) {
  return (
    <div
      className="spa-shimmer"
      style={{
        width,
        height,
        borderRadius,
        display: "inline-block",
        ...style,
      }}
    />
  );
}

export function KpiCardsSkeleton() {
  return (
    <div
      style={{
        display: "grid",
        gridTemplateColumns: "repeat(auto-fit, minmax(220px, 1fr))",
        gap: "16px",
      }}
    >
      {Array.from({ length: 6 }).map((_, idx) => (
        <div
          key={idx}
          style={{
            background: "#ffffff",
            border: "1px solid #e1e3e5",
            borderRadius: "8px",
            padding: "16px",
          }}
        >
          <ShimmerBlock width="90px" height="14px" style={{ marginBottom: "10px" }} />
          <ShimmerBlock width="130px" height="26px" style={{ marginBottom: "12px" }} />
          <div style={{ display: "flex", gap: "8px", alignItems: "center" }}>
            <ShimmerBlock width="64px" height="18px" borderRadius="10px" />
            <ShimmerBlock width="80px" height="12px" />
          </div>
        </div>
      ))}
    </div>
  );
}

export function TrendChartSkeleton() {
  const barHeights = [45, 75, 30, 85, 60, 40, 95, 70, 50, 80, 65, 90, 55, 70];

  return (
    <div
      style={{
        background: "#ffffff",
        border: "1px solid #e1e3e5",
        borderRadius: "8px",
        padding: "16px",
      }}
    >
      <div style={{ display: "flex", justifyContent: "space-between", marginBottom: "16px" }}>
        <ShimmerBlock width="160px" height="16px" />
        <div style={{ display: "flex", gap: "6px" }}>
          <ShimmerBlock width="50px" height="28px" borderRadius="4px" />
          <ShimmerBlock width="50px" height="28px" borderRadius="4px" />
          <ShimmerBlock width="50px" height="28px" borderRadius="4px" />
        </div>
      </div>
      <div
        style={{
          display: "flex",
          alignItems: "flex-end",
          gap: "8px",
          height: "140px",
          borderBottom: "1px solid #e1e3e5",
          paddingBottom: "4px",
        }}
      >
        {barHeights.map((h, i) => (
          <div
            key={i}
            style={{
              flex: 1,
              display: "flex",
              justifyContent: "center",
              alignItems: "flex-end",
              height: "100%",
            }}
          >
            <ShimmerBlock
              width="100%"
              height={`${h}%`}
              borderRadius="3px 3px 0 0"
              style={{ maxWidth: "32px" }}
            />
          </div>
        ))}
      </div>
      <div style={{ display: "flex", justifyContent: "space-between", marginTop: "8px" }}>
        <ShimmerBlock width="80px" height="12px" />
        <ShimmerBlock width="80px" height="12px" />
        <ShimmerBlock width="80px" height="12px" />
      </div>
    </div>
  );
}

export function ProductTableSkeleton() {
  return (
    <div
      style={{
        background: "#ffffff",
        border: "1px solid #e1e3e5",
        borderRadius: "8px",
        overflow: "hidden",
      }}
    >
      <div style={{ padding: "12px 16px", borderBottom: "1px solid #e1e3e5" }}>
        <ShimmerBlock width="260px" height="32px" borderRadius="4px" />
      </div>
      <div style={{ padding: "16px" }}>
        <div
          style={{
            display: "grid",
            gridTemplateColumns: "3fr 1fr 1fr 1fr 1fr",
            gap: "12px",
            borderBottom: "1px solid #e1e3e5",
            paddingBottom: "12px",
            marginBottom: "12px",
          }}
        >
          <ShimmerBlock width="100px" height="14px" />
          <ShimmerBlock width="70px" height="14px" />
          <ShimmerBlock width="70px" height="14px" />
          <ShimmerBlock width="50px" height="14px" />
          <ShimmerBlock width="50px" height="14px" />
        </div>
        {Array.from({ length: 5 }).map((_, i) => (
          <div
            key={i}
            style={{
              display: "grid",
              gridTemplateColumns: "3fr 1fr 1fr 1fr 1fr",
              gap: "12px",
              alignItems: "center",
              padding: "12px 0",
              borderBottom: i < 4 ? "1px solid #f1f2f3" : "none",
            }}
          >
            <div style={{ display: "flex", alignItems: "center", gap: "10px" }}>
              <ShimmerBlock width="36px" height="36px" borderRadius="4px" />
              <div>
                <ShimmerBlock width="160px" height="16px" style={{ marginBottom: "6px" }} />
                <ShimmerBlock width="90px" height="12px" />
              </div>
            </div>
            <ShimmerBlock width="80px" height="16px" />
            <ShimmerBlock width="70px" height="16px" />
            <ShimmerBlock width="40px" height="16px" />
            <ShimmerBlock width="40px" height="16px" />
          </div>
        ))}
      </div>
    </div>
  );
}

export function OverviewDashboardSkeleton() {
  return (
    <div style={{ display: "flex", flexDirection: "column", gap: "20px" }}>
      <ShimmerStyle />
      <KpiCardsSkeleton />
      <TrendChartSkeleton />
      <ProductTableSkeleton />
    </div>
  );
}

export function ProductDetailSkeleton() {
  return (
    <div style={{ display: "flex", flexDirection: "column", gap: "20px" }}>
      <ShimmerStyle />
      {/* Product Header Card Skeleton */}
      <div
        style={{
          background: "#ffffff",
          border: "1px solid #e1e3e5",
          borderRadius: "8px",
          padding: "16px",
          display: "flex",
          alignItems: "center",
          gap: "16px",
        }}
      >
        <ShimmerBlock width="56px" height="56px" borderRadius="6px" />
        <div style={{ flex: 1 }}>
          <div style={{ display: "flex", gap: "10px", alignItems: "center", marginBottom: "8px" }}>
            <ShimmerBlock width="220px" height="22px" />
            <ShimmerBlock width="60px" height="20px" borderRadius="10px" />
          </div>
          <ShimmerBlock width="300px" height="14px" />
        </div>
      </div>
      <KpiCardsSkeleton />
      {/* Variant Table Skeleton */}
      <div
        style={{
          background: "#ffffff",
          border: "1px solid #e1e3e5",
          borderRadius: "8px",
          padding: "16px",
        }}
      >
        <div
          style={{
            display: "grid",
            gridTemplateColumns: "2fr 1fr 1fr 1fr 1fr 1fr 1fr",
            gap: "12px",
            borderBottom: "1px solid #e1e3e5",
            paddingBottom: "12px",
            marginBottom: "12px",
          }}
        >
          {Array.from({ length: 7 }).map((_, i) => (
            <ShimmerBlock key={i} width="60px" height="14px" />
          ))}
        </div>
        {Array.from({ length: 4 }).map((_, i) => (
          <div
            key={i}
            style={{
              display: "grid",
              gridTemplateColumns: "2fr 1fr 1fr 1fr 1fr 1fr 1fr",
              gap: "12px",
              padding: "10px 0",
              borderBottom: i < 3 ? "1px solid #f1f2f3" : "none",
            }}
          >
            {Array.from({ length: 7 }).map((_, j) => (
              <ShimmerBlock key={j} width={j === 0 ? "110px" : "60px"} height="16px" />
            ))}
          </div>
        ))}
      </div>
    </div>
  );
}
