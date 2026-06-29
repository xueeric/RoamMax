/**
 * TypeScript definitions for the Starlink Rental Portal
 */

export interface RentalPlan {
  id: string;
  name: string;
  badge?: string;
  tagline: string;
  kitCost: {
    deposit: number; // Refundable security deposit
    rentalPerWeek: number;
    rentalPerMonth: number;
  };
  features: string[];
  idealFor: string;
  equipmentIncluded: string[];
  techSpec: {
    downloadSpeed: string;
    uploadSpeed: string;
    latency: string;
    powerConsumption: string;
    windRating: string;
  };
}

export interface BookingRequest {
  id: string;
  fullName: string;
  email: string;
  phone: string;
  kitId: string; // The rental plan / kit ID
  startDate: string;
  endDate: string;
  shippingAddress: string;
  city: string;
  state: string;
  zipCode: string;
  specialInstructions?: string;
  totalPrice: number;
  depositAmount: number;
  status: 'pending' | 'confirmed' | 'dispatched' | 'active' | 'completed';
  createdAt: string;
}

export interface FaqItem {
  id: string;
  category: 'rental' | 'setup' | 'billing' | 'coverage';
  question: string;
  answer: string;
}

export interface Testimonial {
  id: string;
  name: string;
  role: string;
  location: string;
  content: string;
  stars: number;
}
