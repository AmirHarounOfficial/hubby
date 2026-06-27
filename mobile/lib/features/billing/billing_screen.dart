import 'package:flutter/material.dart';
import 'package:hubby_global/shared/theme/app_theme.dart';
import 'package:lucide_icons/lucide_icons.dart';

class BillingScreen extends StatelessWidget {
  const BillingScreen({super.key});

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      appBar: AppBar(
        title: const Text('Billing'),
      ),
      body: SingleChildScrollView(
        padding: const EdgeInsets.all(20),
        child: Column(
          children: [
            _buildCurrentPlanCard(),
            const SizedBox(height: 32),
            const Text('Upgrade Your Plan', style: TextStyle(fontWeight: FontWeight.bold, fontSize: 20)),
            const SizedBox(height: 16),
            _buildPlanCard('Starter', '\$29', ['3 Stores', '100 Orders'], false),
            const SizedBox(height: 16),
            _buildPlanCard('Professional', '\$79', ['10 Stores', '1,000 Orders'], true),
            const SizedBox(height: 16),
            _buildPlanCard('Enterprise', '\$199', ['Unlimited Stores', 'Unlimited Orders'], false),
          ],
        ),
      ),
    );
  }

  Widget _buildCurrentPlanCard() {
    return Container(
      padding: const EdgeInsets.all(24),
      decoration: BoxDecoration(
        color: AppTheme.cardColor,
        borderRadius: BorderRadius.circular(24),
        border: Border.all(color: AppTheme.borderColor),
      ),
      child: Column(
        children: [
          Row(
            children: [
              Container(
                padding: const EdgeInsets.all(12),
                decoration: BoxDecoration(
                  color: AppTheme.primaryColor.withOpacity(0.1),
                  borderRadius: BorderRadius.circular(16),
                ),
                child: const Icon(LucideIcons.zap, color: AppTheme.primaryColor),
              ),
              const SizedBox(width: 16),
              const Expanded(
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    Text('Professional Plan', style: TextStyle(fontWeight: FontWeight.bold, fontSize: 18)),
                    Text('Renews on June 5, 2026', style: TextStyle(color: AppTheme.mutedColor, fontSize: 12)),
                  ],
                ),
              ),
            ],
          ),
          const SizedBox(height: 24),
          const Row(
            mainAxisAlignment: MainAxisAlignment.spaceBetween,
            children: [
              Text('Usage this month', style: TextStyle(color: AppTheme.mutedColor, fontSize: 12)),
              Text('12% of 1,000 orders', style: TextStyle(fontWeight: FontWeight.bold, fontSize: 12)),
            ],
          ),
          const SizedBox(height: 8),
          const LinearProgressIndicator(
            value: 0.12,
            backgroundColor: AppTheme.backgroundColor,
            valueColor: AlwaysStoppedAnimation<Color>(AppTheme.primaryColor),
          ),
        ],
      ),
    );
  }

  Widget _buildPlanCard(String name, String price, List<String> features, bool isPopular) {
    return Container(
      padding: const EdgeInsets.all(24),
      decoration: BoxDecoration(
        color: isPopular ? AppTheme.primaryColor.withOpacity(0.05) : AppTheme.cardColor,
        borderRadius: BorderRadius.circular(24),
        border: Border.all(color: isPopular ? AppTheme.primaryColor : AppTheme.borderColor),
      ),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Row(
            mainAxisAlignment: MainAxisAlignment.spaceBetween,
            children: [
              Text(name, style: const TextStyle(fontWeight: FontWeight.bold, fontSize: 18)),
              if (isPopular)
                Container(
                  padding: const EdgeInsets.symmetric(horizontal: 8, vertical: 4),
                  decoration: BoxDecoration(
                    color: AppTheme.primaryColor,
                    borderRadius: BorderRadius.circular(8),
                  ),
                  child: const Text('POPULAR', style: TextStyle(color: Colors.white, fontSize: 8, fontWeight: FontWeight.bold)),
                ),
            ],
          ),
          const SizedBox(height: 8),
          Row(
            crossAxisAlignment: CrossAxisAlignment.baseline,
            textBaseline: TextBaseline.alphabetic,
            children: [
              Text(price, style: const TextStyle(fontSize: 28, fontWeight: FontWeight.bold)),
              const Text('/mo', style: TextStyle(color: AppTheme.mutedColor, fontSize: 14)),
            ],
          ),
          const SizedBox(height: 20),
          ...features.map((f) => Padding(
            padding: const EdgeInsets.only(bottom: 8),
            child: Row(
              children: [
                const Icon(LucideIcons.check, color: AppTheme.secondaryColor, size: 16),
                const SizedBox(width: 8),
                Text(f, style: const TextStyle(fontSize: 14)),
              ],
            ),
          )),
          const SizedBox(height: 24),
          ElevatedButton(
            onPressed: () {},
            style: ElevatedButton.styleFrom(
              backgroundColor: isPopular ? AppTheme.primaryColor : AppTheme.backgroundColor,
              minimumSize: const Size(double.infinity, 50),
              side: isPopular ? null : const BorderSide(color: AppTheme.borderColor),
            ),
            child: Text(isPopular ? 'Current Plan' : 'Select $name'),
          ),
        ],
      ),
    );
  }
}
