import 'package:flutter/material.dart';
import 'package:hubby_global/shared/theme/app_theme.dart';
import 'package:lucide_icons/lucide_icons.dart';

class OrdersScreen extends StatelessWidget {
  const OrdersScreen({super.key});

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      appBar: AppBar(
        title: const Text('Orders'),
        actions: [
          IconButton(onPressed: () {}, icon: const Icon(LucideIcons.filter, size: 20)),
          IconButton(onPressed: () {}, icon: const Icon(LucideIcons.search, size: 20)),
        ],
      ),
      body: Column(
        children: [
          _buildPlatformFilter(),
          Expanded(
            child: ListView.separated(
              padding: const EdgeInsets.all(20),
              itemCount: 10,
              separatorBuilder: (_, __) => const SizedBox(height: 16),
              itemBuilder: (context, index) {
                return _buildOrderCard(index);
              },
            ),
          ),
        ],
      ),
    );
  }

  Widget _buildPlatformFilter() {
    final platforms = ['All', 'Shopify', 'Salla', 'Zid', 'Woo'];
    return SizedBox(
      height: 50,
      child: ListView.separated(
        padding: const EdgeInsets.symmetric(horizontal: 20),
        scrollDirection: Axis.horizontal,
        itemCount: platforms.length,
        separatorBuilder: (_, __) => const SizedBox(width: 10),
        itemBuilder: (context, index) {
          final isSelected = index == 0;
          return ChoiceChip(
            label: Text(platforms[index]),
            selected: isSelected,
            onSelected: (_) {},
            selectedColor: AppTheme.primaryColor,
            backgroundColor: AppTheme.cardColor,
            labelStyle: TextStyle(
              color: isSelected ? Colors.white : AppTheme.mutedColor,
              fontSize: 12,
              fontWeight: FontWeight.bold,
            ),
            shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(10)),
          );
        },
      ),
    );
  }

  Widget _buildOrderCard(int index) {
    final statuses = ['Processing', 'Paid', 'Shipped', 'Pending'];
    final status = statuses[index % 4];
    
    return Container(
      padding: const EdgeInsets.all(16),
      decoration: BoxDecoration(
        color: AppTheme.cardColor,
        borderRadius: BorderRadius.circular(20),
        border: Border.all(color: AppTheme.borderColor),
      ),
      child: Column(
        children: [
          Row(
            mainAxisAlignment: MainAxisAlignment.spaceBetween,
            children: [
              Text(
                '#100${index + 10}',
                style: const TextStyle(fontWeight: FontWeight.bold, color: AppTheme.primaryColor),
              ),
              _buildStatusBadge(status),
            ],
          ),
          const SizedBox(height: 16),
          Row(
            children: [
              const Icon(LucideIcons.user, size: 14, color: AppTheme.mutedColor),
              const SizedBox(width: 8),
              const Text('Amir T.', style: TextStyle(fontSize: 14)),
              const Spacer(),
              const Text('\$45.00', style: TextStyle(fontWeight: FontWeight.bold, fontSize: 16)),
            ],
          ),
          const SizedBox(height: 12),
          const Divider(color: AppTheme.borderColor),
          const SizedBox(height: 12),
          Row(
            children: [
              const Icon(LucideIcons.shoppingBag, size: 14, color: Colors.green),
              const SizedBox(width: 8),
              const Text('Shopify', style: TextStyle(color: AppTheme.mutedColor, fontSize: 12)),
              const Spacer(),
              const Text('2 mins ago', style: TextStyle(color: AppTheme.mutedColor, fontSize: 12)),
            ],
          ),
        ],
      ),
    );
  }

  Widget _buildStatusBadge(String status) {
    Color color;
    switch (status) {
      case 'Paid': color = AppTheme.secondaryColor; break;
      case 'Processing': color = AppTheme.primaryColor; break;
      case 'Shipped': color = Colors.blueAccent; break;
      default: color = Colors.orangeAccent;
    }

    return Container(
      padding: const EdgeInsets.symmetric(horizontal: 8, vertical: 4),
      decoration: BoxDecoration(
        color: color.withOpacity(0.1),
        borderRadius: BorderRadius.circular(8),
      ),
      child: Text(
        status.toUpperCase(),
        style: TextStyle(color: color, fontSize: 10, fontWeight: FontWeight.bold),
      ),
    );
  }
}
