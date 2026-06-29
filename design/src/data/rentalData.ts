import { RentalPlan, FaqItem, Testimonial } from '../types';

export const RENTAL_PLANS: RentalPlan[] = [
  {
    id: 'standard-kit',
    name: 'Starlink Standard (V4)',
    badge: 'Most Popular',
    tagline: 'Reliable, portable satellite internet ideal for individuals, remote work, camping, and regional events.',
    kitCost: {
      deposit: 150,
      rentalPerWeek: 45,
      rentalPerMonth: 149
    },
    idealFor: 'Temporary home replacement, RV getaways, regional pop-up events, and individuals in need of rapid deploy.',
    features: [
      'Automatic auto-heating for snow/ice mitigation',
      'Ultra portable, sets up in less than 5 minutes',
      'Includes Gen 3 Wi-Fi Router (Wi-Fi 6 tri-band support)',
      '15-meter Starlink ethernet/power cable included',
      'No data caps (Unlimited high-speed data)'
    ],
    equipmentIncluded: [
      'Starlink Standard Dish (V4 Kickstand)',
      '15m (49.2 ft) Starlink Cable',
      'Gen 3 Wi-Fi 6 Router',
      'AC Power Cable & Power Supply Unit',
      'Heavy-duty Protective Rugged Transit Case'
    ],
    techSpec: {
      downloadSpeed: '100 - 220 Mbps',
      uploadSpeed: '10 - 25 Mbps',
      latency: '25 - 40 ms',
      powerConsumption: 'Average: 50-75W (Peak: 110W)',
      windRating: 'Survival: 110 kph+ (70 mph+)'
    }
  },
  {
    id: 'flat-high-performance',
    name: 'Flat High Performance (In-Motion)',
    badge: 'Mobile & Marine',
    tagline: 'Engineered for in-motion vehicle use, marine vessels, and absolute maximum satellite visibility.',
    kitCost: {
      deposit: 350,
      rentalPerWeek: 125,
      rentalPerMonth: 395
    },
    idealFor: 'Caravans/RVs in motion, marine transit, scientific expeditions, and permanent remote business backups.',
    features: [
      'Approved for dual-axis in-motion use worldwide',
      '140° field of view (double the standard model coverage)',
      'Extreme temperature durability & superior GPS tracker',
      'Includes enterprise-grade power injector & mounting accessories',
      'Priority prioritization on high-density cells'
    ],
    equipmentIncluded: [
      'Flat High Performance Starlink Dish (with Wedge Mount)',
      '25m (82 ft) Shielded Starlink Cable',
      'Starlink Router with Built-In Ethernet Adapter',
      '8m (26.2 ft) Router Cable',
      'Power Supply & High-Capacity PoE Injector',
      'Military-grade Hard Flight-Case with customized foam'
    ],
    techSpec: {
      downloadSpeed: '150 - 350 Mbps',
      uploadSpeed: '20 - 45 Mbps',
      latency: '20 - 35 ms',
      powerConsumption: 'Average: 110-150W (Peak: 200W)',
      windRating: 'Survival: 280 kph+ (174 mph+)'
    }
  },
  {
    id: 'enterprise-kit',
    name: 'Enterprise High Performance',
    badge: 'Business Class',
    tagline: 'High-performance dish with wider field of view, ideal for multi-user events and rigorous industrial operations.',
    kitCost: {
      deposit: 250,
      rentalPerWeek: 95,
      rentalPerMonth: 299
    },
    idealFor: 'Music festivals, temporary construction sites, broadcast streaming, and primary mission-critical office backup.',
    features: [
      'Significantly higher satellite connection density',
      'Excellent performance in extremely cold environments',
      'Supports up to 250+ clients simultaneously without bottlenecks',
      'Includes robust metal ground stand & wall mount option',
      'Dedicated static IP compatibility available'
    ],
    equipmentIncluded: [
      'Premium High Performance Dish',
      'Power Supply Unit (PSU)',
      'Enterprise Router & 30m (98 ft) heavy-duty cables',
      'Ground Stand mount & heavy ballast anchor weight',
      'Waterproof Pelican Transport Case with locking latches'
    ],
    techSpec: {
      downloadSpeed: '220 - 400+ Mbps',
      uploadSpeed: '25 - 60 Mbps',
      latency: '20 - 35 ms',
      powerConsumption: 'Average: 75-100W (Peak: 160W)',
      windRating: 'Survival: 200 kph+ (125 mph+)'
    }
  }
];

export const FAQS: FaqItem[] = [
  {
    id: 'rent-1',
    category: 'rental',
    question: 'How does the Starlink rental process work?',
    answer: 'It is incredibly simple: 1) Pick your equipment kit (Standard, Flat In-Motion, or Enterprise) and input your dates. 2) Submit your booking request. 3) We ship your kit in a rugged waterproof protective transit case with a pre-paid FedEx return shipping label inside. 4) Take it anywhere in the world, use it, and pack it back. On your rental end-date, drop it off at any authorized shipping carrier using our pre-paid label.'
  },
  {
    id: 'rent-2',
    category: 'rental',
    question: 'Is the security deposit refundable?',
    answer: 'Yes, absolutely! The security deposit is held securely. Within 3 business days of returning the kit to our warehouse, our technicians inspect the components. Once verified complete and in working order, the deposit is refunded 100% back to your original payment method.'
  },
  {
    id: 'setup-1',
    category: 'setup',
    question: 'How long does it take to set up? Do I need professional installation?',
    answer: 'You do NOT need any tools or technical expertise. The Starlink kit is designed for immediate self-setup. Average time is 3 to 5 minutes: Place the dish outside with a clear view of the open sky (no trees or overhang over the top), plug in the power supply, and connect to the default Wi-Fi network shown. The dish handles all self-leveling and directional configuration automatically.'
  },
  {
    id: 'setup-2',
    category: 'setup',
    question: 'What constitutes an "obstruction" for the Starlink antenna?',
    answer: 'Starlink needs a wide, unobstructed view of the sky overhead to track satellites as they move across orbit. Tree branches, roofs, light poles, or tall dry grass directly blocking the signal to the sky will cause micro-interruptions or speed drops. We suggest downloading the official Starlink Mobile App to use its built-in camera tool to pre-scan your setup location for any minor sky obstructions!'
  },
  {
    id: 'billing-1',
    category: 'billing',
    question: 'Are there any data caps or usage restrictions?',
    answer: 'Never. All our rental plans include unlimited raw high-speed data. There are no throttling margins, bandwidth caps, or unexpected surcharge bills, even if you stream 4K movies or operate a high-volume work site all month long.'
  },
  {
    id: 'billing-2',
    category: 'rental',
    question: 'Can I extend my rental space mid-way through my trip?',
    answer: 'Absolutely! If your expedition, film shoot, or temporary residential backup needs more time, just log back into the portal here or email us minimum 48 hours prior to your scheduled return. We will extend your rental based on current tier availability rates.'
  },
  {
    id: 'coverage-1',
    category: 'coverage',
    question: 'Will Starlink work in my specific location (e.g. deep wilderness)?',
    answer: 'Yes! Starlink operates using thousands of low earth orbit (LEO) satellites. As long as you have an environment with a physical view of the open sky (free of heavy tree canopy or adjacent cliff blockages), it will connect seamlessly anywhere across North America, marine environments, and designated global countries. You can use our "Real-Time Coverage Checker" at the top of this page to view satellite metrics for your exact ZIP!'
  }
];

export const TESTIMONIALS: Testimonial[] = [
  {
    id: 'test-1',
    name: 'Sarah Jenkins',
    role: 'Remote Video Editor & Producer',
    location: 'Moab Desert, Utah',
    content: 'Rented the Standard V4 kit for a 2-week off-grid production campaign. Blown away by finding 180 Mbps download speeds in deep canyons. The rugged transit case survived dust and heat easily.',
    stars: 5
  },
  {
    id: 'test-2',
    name: 'Marcus Vance',
    role: 'Disaster Relief Site Coordinator',
    location: 'Asheville, North Carolina',
    content: 'After a severe hurricane severed cellular networks, we ordered three Enterprise kits. Rapid overnight express delivery and setup took less than 3 minutes per unit. Provided critical mesh trunking for 120 rescue workers.',
    stars: 5
  },
  {
    id: 'test-3',
    name: 'Capt. David K.',
    role: 'Private Yacht Operator',
    location: 'Caribbean / Bahamas',
    content: 'The Flat High Performance kit is a total game changer for in-motion marine access. It held steady through rolling seas and gave our guests reliable Zoom calls 40 miles off-shore.',
    stars: 5
  }
];

// Helper function to simulate checking geo-satellite availability
export interface CoverageReport {
  status: 'excellent' | 'good' | 'average' | 'unsupported';
  message: string;
  satelliteDensity: number; // 0-100 rating
  averagePing: number; // ms
  recommendedKitId: string;
  latitudeEstimate: string;
}

export function checkAvailabilityMock(zipOrCity: string): CoverageReport {
  const sanitized = zipOrCity.trim().toLowerCase();
  
  if (!sanitized) {
    return {
      status: 'average',
      message: 'Enter an address or ZIP to analyze high-speed satellite orbital density.',
      satelliteDensity: 65,
      averagePing: 38,
      recommendedKitId: 'standard-kit',
      latitudeEstimate: '37.77° N'
    };
  }

  // Generate deterministic simulation values based on input hash string
  let hash = 0;
  for (let i = 0; i < sanitized.length; i++) {
    hash = (hash << 5) - hash + sanitized.charCodeAt(i);
    hash |= 0; // Convert to 32bit integer
  }
  hash = Math.abs(hash);

  const isZip = /^\d{5}$/.test(sanitized);
  const density = 72 + (hash % 24); // Rating between 72 and 96
  const ping = 25 + (hash % 16); // Ping between 25 and 41ms
  const latDeg = 24 + (hash % 25);
  const latMin = hash % 60;
  const recommendedId = hash % 3 === 0 ? 'flat-high-performance' : (hash % 3 === 1 ? 'enterprise-kit' : 'standard-kit');

  if (sanitized === 'unsupported' || sanitized === 'void' || sanitized === '00000') {
    return {
      status: 'unsupported',
      message: 'Orbits are currently congested or under regional embargo in this sector. Standard signals might be blocked.',
      satelliteDensity: 12,
      averagePing: 120,
      recommendedKitId: 'standard-kit',
      latitudeEstimate: '0.00° Ocean'
    };
  }

  if (isZip) {
    const firstDigit = sanitized[0];
    if (['8', '9', '7'].includes(firstDigit)) {
      return {
        status: 'excellent',
        message: 'Perfect Signal! Highly optimal satellite density with wide sky-field clearance. Ideal for ultra-low latency Roam or Standard kits.',
        satelliteDensity: density,
        averagePing: ping - 4,
        recommendedKitId: 'standard-kit',
        latitudeEstimate: `${latDeg}.${latMin}° W`
      };
    } else if (['0', '1', '2'].includes(firstDigit)) {
      return {
        status: 'good',
        message: 'Dense Signal Coverage. Strong orbital overlapping. Standard Wi-Fi 6 dishes will operate at maximum capacity here.',
        satelliteDensity: Math.max(78, density - 5),
        averagePing: ping,
        recommendedKitId: 'standard-kit',
        latitudeEstimate: `${latDeg + 8}.${latMin}° N`
      };
    } else {
      return {
        status: 'excellent',
        message: 'Excellent Coverage Spot. High-altitude orbital grids are fully active. Fast connections guaranteed with clear sky views.',
        satelliteDensity: density,
        averagePing: ping - 1,
        recommendedKitId: recommendedId,
        latitudeEstimate: `${latDeg + 5}.${latMin}° N`
      };
    }
  }

  // If search matches common remote wilderness words
  if (sanitized.includes('desert') || sanitized.includes('national park') || sanitized.includes('lake') || sanitized.includes('camp') || sanitized.includes('mountain') || sanitized.includes('sea') || sanitized.includes('forest')) {
    return {
      status: 'excellent',
      message: 'Optimal Wild-Land Spot. Perfect for Roam (Standard) or Flat In-Motion kits. Low electromagnetic clutter yields stellar performance.',
      satelliteDensity: 94,
      averagePing: 29,
      recommendedKitId: 'flat-high-performance',
      latitudeEstimate: `${latDeg}.${latMin}° N`
    };
  }

  return {
    status: 'good',
    message: `Active Satellite Link Available. High orbital density confirmed over this sector. Supports gaming, video edits, and multiple HD video streams.`,
    satelliteDensity: density,
    averagePing: ping,
    recommendedKitId: 'standard-kit',
    latitudeEstimate: `${latDeg}.${latMin}° N`
  };
}
