import 'package:flutter/material.dart';
import 'package:hubby_global/shared/theme/app_theme.dart';
import 'package:lucide_icons/lucide_icons.dart';

class InventoryScreen extends StatelessWidget {
  const InventoryScreen({super.key});

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      appBar: AppBar(
        title: const Text('Inventory'),
        actions: [
          IconButton(onPressed: () {}, icon: const Icon(LucideIcons.history, size: 20)),
          IconButton(onPressed: () {}, icon: const Icon(LucideIcons.refreshCw, size: 20)),
        ],
      ),
      body: SingleChildScrollView(
        padding: const EdgeInsets.all(20),
        child: Column(
          children: [
            _buildGlobalSyncCard(),
            const SizedBox(height: 24),
            Row(
              mainAxisAlignment: MainAxisAlignment.spaceBetween,
              children: [
                const Text('Product Stock', style: TextStyle(fontWeight: FontWeight.bold, fontSize: 18)),
                TextButton(onPressed: () {}, child: const Text('Filters')),
              ],
            ),
            const SizedBox(height: 12),
            ListView.separated(
              shrinkWrap: true,
              physics: const NeverScrollableScrollPhysics(),
              itemCount: 5,
              separatorBuilder: (_, __) => const SizedBox(height: 12),
              itemBuilder: (context, index) {
                return _buildInventoryItem(index);
              },
            ),
          ],
        ),
      ),
    );
  }

  Widget _buildGlobalSyncCard() {
    return Container(
      padding: const EdgeInsets.all(24),
      decoration: BoxDecoration(
        gradient: const LinearGradient(
          colors: [AppTheme.primaryColor, Color(0xFF6366F1)],
        ),
        borderRadius: BorderRadius.circular(24),
        boxShadow: [
          BoxShadow(color: AppTheme.primaryColor.withOpacity(0.3), blurRadius: 20, offset: const Offset(0, 10)),
        ],
      ),
      child: Column(
        children: [
          const Row(
            children: [
              Icon(LucideIcons.zap, color: Colors.white, size: 28),
              SizedBox(width: 12),
              Text('Global Sync Active', style: TextStyle(color: Colors.white, fontWeight: FontWeight.bold, fontSize: 16)),
              Spacer(),
              Icon(LucideIcons.checkCircle2, color: AppTheme.secondaryColor, size: 20),
            ],
          ),
          const SizedBox(height: 16),
          const Text(
            'Master store inventory is automatically pushed to all 4 secondary channels.',
            style: TextStyle(color: Colors.white70, fontSize: 12),
          ),
          const SizedBox(height: 20),
          ElevatedButton(
            onPressed: () {},
            style: ElevatedButton.styleFrom(
              backgroundColor: Colors.white,
              foregroundColor: AppTheme.primaryColor,
              minimumSize: const Size(double.infinity, 50),
            ),
            child: const Text('Force Global Push'),
          ),
        ],
      ),
    );
  }

  Widget _buildInventoryItem(int index) {
    final stocks = [45, 12, 89, 0, 156];
    final stock = stocks[index % 5];
    final isLow = stock > 0 && stock < 20;
    final isOut = stock == 0;

    return Container(
      padding: const EdgeInsets.all(16),
      decoration: BoxDecoration(
        color: AppTheme.cardColor,
        borderRadius: BorderRadius.circular(20),
        border: Border.all(color: AppTheme.borderColor),
      ),
      child: Row(
        children: [
          Container(
            width: 50,
            height: 50,
            decoration: BoxDecoration(
              color: AppTheme.backgroundColor,
              borderRadius: BorderRadius.circular(12),
            ),
            child: const Icon(LucideIcons.package, color: AppTheme.mutedColor),
          ),
          const SizedBox(width: 16),
          Expanded(
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                const Text('Premium Leather Bag', style: TextStyle(fontWeight: FontWeight.bold, fontSize: 14)),
                const Text('LB-001 • Shopify Master', style: TextStyle(fontSize: 10, color: AppTheme.mutedColor)),
              ],
            ),
          ),
          Column(
            crossAxisAlignment: CrossAxisAlignment.end,
            children: [
              Text(
                stock.toString(),
                style: TextStyle(
                  fontSize: 18, 
                  fontWeight: FontWeight.bold,
                  color: isOut ? Colors.redAccent : isLow ? Colors.orangeAccent : AppTheme.foregroundColor,
                ),
              ),
              Text(
                isOut ? 'OUT' : isLow ? 'LOW' : 'UNITS',
                style: TextStyle(
                  fontSize: 8, 
                  fontWeight: FontWeight.bold,
                  color: isOut ? Colors.redAccent : isLow ? Colors.orangeAccent : AppTheme.mutedColor,
                ),
              ),
            ],
          ),
          const SizedBox(width: 8),
          const Icon(LucideIcons.chevronRight, size: 16, color: AppTheme.mutedColor),
        ],
      ),
    );
  }
}
