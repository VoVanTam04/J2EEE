package J2EE.bai4.repository;

import J2EE.bai4.model.Order;
import org.springframework.data.jpa.repository.JpaRepository;
import org.springframework.stereotype.Repository;

import java.util.List;

@Repository
public interface OrderRepository extends JpaRepository<Order, Integer> {

    List<Order> findByAccountIdOrderByOrderDateDesc(Integer accountId);
}
