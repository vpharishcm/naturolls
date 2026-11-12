import type { Product } from '../lib/types';

/** Product photo when uploaded, otherwise the illustrations from the Naturolls design. */
export function ProductArt({ product, size = 72 }: { product: Pick<Product, 'art' | 'art_color' | 'image_url' | 'name'>; size?: number }) {
  if (product.image_url) {
    return <img src={product.image_url} alt={product.name} style={{ width: size, height: size }} className="rounded-2xl object-cover" loading="lazy" />;
  }
  const color = product.art_color ?? '#F3E2C0';
  const common = { width: size, height: size, viewBox: '0 0 72 72', role: 'img', 'aria-label': product.name };
  switch (product.art) {
    case 'mango':
      return (
        <svg {...common}>
          <ellipse cx="36" cy="40" rx="22" ry="27" fill="#FFB020" transform="rotate(-20 36 40)" />
          <ellipse cx="42" cy="34" rx="9" ry="14" fill="#F26B3A" opacity="0.35" transform="rotate(-20 36 40)" />
          <path d="M38 14 Q44 4 54 6 Q48 16 38 14Z" fill="#3BAA5A" />
        </svg>
      );
    case 'strawberry':
      return (
        <svg {...common}>
          <path d="M36 68 C14 54 9 38 14 27 C18 17 30 14 36 23 C42 14 54 17 58 27 C63 38 58 54 36 68Z" fill="#E8374F" />
          <path d="M24 20 L36 27 L48 20 L44 16 L36 22 L28 16Z" fill="#3BAA5A" />
          <g fill="#FFE9A8">
            <ellipse cx="26" cy="36" rx="1.6" ry="2.4" /><ellipse cx="44" cy="38" rx="1.6" ry="2.4" /><ellipse cx="36" cy="48" rx="1.6" ry="2.4" /><ellipse cx="25" cy="52" rx="1.6" ry="2.4" /><ellipse cx="48" cy="52" rx="1.6" ry="2.4" />
          </g>
        </svg>
      );
    case 'orange':
      return (
        <svg {...common}>
          <circle cx="36" cy="38" r="27" fill="#FF8A1F" />
          <circle cx="36" cy="38" r="22" fill="#FFB15C" />
          <path d="M36 16 V60 M16 38 H56 M22 24 L50 52 M50 24 L22 52" stroke="#FFE2B8" strokeWidth="2.5" />
          <path d="M36 11 Q42 4 50 8 Q44 13 36 11Z" fill="#3BAA5A" />
        </svg>
      );
    case 'pineapple':
      return (
        <svg {...common}>
          <path d="M30 22 L26 2 L36 16 L40 0 L46 22Z" fill="#3BAA5A" />
          <ellipse cx="36" cy="42" rx="20" ry="25" fill="#F7C935" />
          <path d="M20 34 L36 50 M18 46 L30 58 M30 24 L50 44 M44 22 L54 36 M24 26 L38 40" stroke="#C98F12" strokeWidth="1.6" fill="none" />
        </svg>
      );
    case 'grape':
      return (
        <svg {...common}>
          <path d="M36 12 Q42 2 50 6 Q44 14 36 12Z" fill="#3BAA5A" />
          <g fill="#7C5CFF">
            <circle cx="28" cy="24" r="8" /><circle cx="44" cy="24" r="8" /><circle cx="20" cy="38" r="8" /><circle cx="36" cy="38" r="8" /><circle cx="52" cy="38" r="8" /><circle cx="28" cy="52" r="8" /><circle cx="44" cy="52" r="8" /><circle cx="36" cy="64" r="7" />
          </g>
          <g fill="#fff" opacity="0.4">
            <circle cx="25" cy="21" r="2" /><circle cx="41" cy="21" r="2" /><circle cx="17" cy="35" r="2" /><circle cx="33" cy="35" r="2" />
          </g>
        </svg>
      );
    case 'waffle':
      return (
        <svg {...common}>
          <rect x="12" y="26" width="48" height="38" rx="7" fill="#E9B45A" />
          <path d="M12 39 H60 M12 52 H60 M26 26 V64 M42 26 V64" stroke="#C98A2E" strokeWidth="2.5" />
          <path d="M14 26 Q14 12 36 10 Q58 12 58 26 Z" fill={color} />
        </svg>
      );
    default:
      return (
        <svg {...common}>
          <rect x="33" y="48" width="6" height="20" rx="3" fill="#D9B88F" />
          <path d="M18 30 Q18 8 36 8 Q54 8 54 30 L54 56 Q54 64 46 64 L26 64 Q18 64 18 56 Z" fill={color} stroke="#E2CDAA" strokeWidth="1.5" />
          <ellipse cx="27" cy="24" rx="3" ry="8" fill="#fff" opacity="0.5" />
        </svg>
      );
  }
}
