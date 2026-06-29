import React, { useState } from 'react';
import { Calculator, Truck, Layers, RefreshCw } from 'lucide-react';
import { RENTAL_PLANS } from '../data/rentalData';

export default function RentalCalculator() {
  const [selectedKitId, setSelectedKitId] = useState(RENTAL_PLANS[0].id);
  const [weeks, setWeeks] = useState(2);
  const [shippingMethod, setShippingMethod] = useState<'standard' | 'express'>('standard');

  const selectedPlan = RENTAL_PLANS.find(p => p.id === selectedKitId) || RENTAL_PLANS[0];

  // Pricing calculations
  const calculateTotal = () => {
    // Determine the optimal pricing term (months vs weeks)
    const months = Math.floor(weeks / 4);
    const remainingWeeks = weeks % 4;

    const baseRentalCost = (months * selectedPlan.kitCost.rentalPerMonth) + (remainingWeeks * selectedPlan.kitCost.rentalPerWeek);
    const securityDeposit = selectedPlan.kitCost.deposit;
    const shippingCost = shippingMethod === 'standard' ? 25 : 60;
    const estimatedTax = Math.round(baseRentalCost * 0.08);

    return {
      monthsUsed: months,
      weeksUsed: remainingWeeks,
      rentalFee: baseRentalCost,
      deposit: securityDeposit,
      shipping: shippingCost,
      tax: estimatedTax,
      totalDueNow: baseRentalCost + securityDeposit + shippingCost + estimatedTax,
      totalRefundable: securityDeposit,
      finalNetCost: baseRentalCost + shippingCost + estimatedTax
    };
  };

  const costResult = calculateTotal();

  return (
    <div className="rounded-2xl border border-gray-100 bg-white p-6 sm:p-8 shadow-sm relative overflow-hidden" id="calculator-widget">
      <div className="absolute top-0 right-0 h-32 w-32 rounded-full bg-gray-50 blur-2xl" />
      
      <div className="border-b border-gray-100 pb-5 mb-6">
        <h3 className="font-display text-lg font-bold text-gray-900 flex items-center space-x-2">
          <Calculator className="h-5 w-5 text-black" />
          <span>Interactive Cost Estimator</span>
        </h3>
        <p className="text-xs text-gray-500 mt-1">
          Adjust configuration parameters to estimate rental, deposit, and shipping totals instantly.
        </p>
      </div>

      <div className="grid gap-6 md:grid-cols-2">
        {/* Left Side Inputs */}
        <div className="space-y-5">
          {/* Kit Type Selection */}
          <div>
            <label className="block font-mono text-[9px] uppercase tracking-wider text-gray-400 font-bold mb-2">
              1. Hardware Kit
            </label>
            <div className="grid grid-cols-1 gap-2.5">
              {RENTAL_PLANS.map(plan => (
                <button
                  key={plan.id}
                  type="button"
                  onClick={() => setSelectedKitId(plan.id)}
                  className={`flex items-center justify-between rounded-xl border p-3.5 text-left text-xs font-semibold tracking-wide transition leading-relaxed cursor-pointer w-full ${
                    selectedKitId === plan.id
                      ? 'border-black bg-gray-50 text-black shadow-sm font-bold'
                      : 'border-gray-200 bg-white text-gray-600 hover:border-gray-400'
                  }`}
                  id={`calc-kit-btn-${plan.id}`}
                >
                  <div className="flex items-center space-x-2.5">
                    <Layers className={`h-4 w-4 ${selectedKitId === plan.id ? 'text-black' : 'text-gray-400'}`} />
                    <div>
                      <p className="text-xs font-bold">{plan.name}</p>
                      <p className="text-[10px] text-gray-500 font-normal mt-0.5">
                        ${plan.kitCost.rentalPerWeek}/wk or ${plan.kitCost.rentalPerMonth}/mo
                      </p>
                    </div>
                  </div>
                  <span className="font-mono text-[10px] font-bold text-gray-500">${plan.kitCost.deposit} Deposit</span>
                </button>
              ))}
            </div>
          </div>

          {/* Duration Slider */}
          <div>
            <div className="flex justify-between items-baseline mb-2">
              <label className="block font-mono text-[9px] uppercase tracking-wider text-gray-400 font-bold">
                2. Rental Duration
              </label>
              <span className="font-mono text-[10px] font-bold text-white bg-black px-2.5 py-0.5 rounded-full">
                {weeks} Week{weeks > 1 ? 's' : ''} ({weeks * 7} Days)
              </span>
            </div>
            <div className="space-y-2">
              <input
                type="range"
                min="1"
                max="12"
                step="1"
                value={weeks}
                onChange={(e) => setWeeks(Number(e.target.value))}
                className="w-full h-1.5 bg-gray-200 rounded-lg appearance-none cursor-pointer accent-black"
                id="calc-duration-range"
              />
              <div className="flex justify-between text-[10px] font-mono text-gray-400">
                <span>1 Week</span>
                <span>4 Weeks (1 mo)</span>
                <span>8 Weeks</span>
                <span>12 Weeks (3 mo)</span>
              </div>
            </div>
          </div>

          {/* Shipping Choice */}
          <div>
            <label className="block font-mono text-[9px] uppercase tracking-wider text-gray-400 font-bold mb-2">
              3. Transit &amp; Delivery Option
            </label>
            <div className="grid grid-cols-2 gap-3">
              <button
                type="button"
                onClick={() => setShippingMethod('standard')}
                className={`flex flex-col rounded-xl border p-3.5 text-left text-xs font-semibold tracking-wide transition cursor-pointer w-full ${
                  shippingMethod === 'standard'
                    ? 'border-black bg-gray-50 text-black shadow-sm font-bold'
                    : 'border-gray-200 bg-white text-gray-600 hover:border-gray-400'
                }`}
                id="calc-shipping-std"
              >
                <div className="flex items-center space-x-1.5 text-gray-500">
                  <Truck className="h-4 w-4 text-gray-600" />
                  <span className="font-mono text-[9px] uppercase font-bold tracking-wide">Standard Courier</span>
                </div>
                <span className="text-sm font-extrabold mt-1">$25 Flat</span>
                <span className="text-[10px] text-gray-500 font-normal mt-0.5">3-5 business days</span>
              </button>

              <button
                type="button"
                onClick={() => setShippingMethod('express')}
                className={`flex flex-col rounded-xl border p-3.5 text-left text-xs font-semibold tracking-wide transition cursor-pointer w-full ${
                  shippingMethod === 'express'
                    ? 'border-black bg-gray-50 text-black shadow-sm font-bold'
                    : 'border-gray-200 bg-white text-gray-600 hover:border-gray-400'
                }`}
                id="calc-shipping-exp"
              >
                <div className="flex items-center space-x-1.5 text-gray-500">
                  <RefreshCw className="h-3.5 w-3.5 text-black hover:scale-105" />
                  <span className="font-mono text-[9px] uppercase font-bold tracking-wide">Priority Express</span>
                </div>
                <span className="text-sm font-extrabold mt-1">$60 Flat</span>
                <span className="text-[10px] text-gray-500 font-normal mt-0.5">Overnight / 2 days</span>
              </button>
            </div>
          </div>
        </div>

        {/* Right Side Billing Invoice Display */}
        <div className="flex flex-col justify-between rounded-xl bg-gray-50 p-5 border border-gray-200 md:h-full">
          <div>
            <span className="font-mono text-[9px] uppercase tracking-widest text-gray-400 font-bold block mb-4">
              Booking Invoice Estimate
            </span>
            
            <div className="space-y-2.5 font-sans text-xs text-gray-700">
              <div className="flex justify-between">
                <span className="text-gray-500 font-medium">Starlink Rental Fee ({weeks} wk)</span>
                <span className="text-gray-900 font-bold font-mono">${costResult.rentalFee}</span>
              </div>
              
              {costResult.monthsUsed > 0 && (
                <div className="text-[10px] text-emerald-800 font-mono bg-emerald-50 px-2 py-0.5 rounded inline-block self-start font-bold">
                  🎉 Applied monthly long-term saver rate
                </div>
              )}

              <div className="flex justify-between">
                <span className="text-gray-500 font-medium">Refundable Hardware Security Deposit</span>
                <span className="text-gray-900 font-bold font-mono">${costResult.deposit}</span>
              </div>
              
              <div className="flex justify-between">
                <span className="text-gray-500 font-medium">Case Delivery &amp; Pre-paid Return Shipping</span>
                <span className="text-gray-900 font-bold font-mono">${costResult.shipping}</span>
              </div>

              <div className="flex justify-between">
                <span className="text-gray-500 font-medium">Estimated Logistics Tax (8%)</span>
                <span className="text-gray-900 font-bold font-mono">${costResult.tax}</span>
              </div>
            </div>

            <div className="mt-5 pt-5 border-t border-gray-200 space-y-3">
              <div className="flex justify-between items-baseline">
                <span className="text-xs font-bold text-gray-800">Total Authorized Amount:</span>
                <span className="text-2xl font-bold font-mono text-gray-900 tracking-tight">
                  ${costResult.totalDueNow}
                </span>
              </div>

              <div className="rounded-lg bg-emerald-50 border border-emerald-100 p-3.5 text-[11px] text-emerald-800 leading-relaxed font-sans">
                <p className="font-semibold font-mono text-[9px] uppercase tracking-wider">Shield Refund Policy:</p>
                <p className="mt-0.5 text-xs">
                  <strong>${costResult.totalRefundable}</strong> is credited back fully after verified kit check-in. The absolute net cost for your trip is only <strong>${costResult.finalNetCost}</strong>.
                </p>
              </div>
            </div>
          </div>

          <div className="mt-6 text-[10px] text-gray-400 leading-relaxed bg-white p-3 rounded-lg border border-gray-150">
            * Estimates are subject to slight variance based on recipient state sales tax rules structured during actual submission checkout.
          </div>
        </div>
      </div>
    </div>
  );
}
